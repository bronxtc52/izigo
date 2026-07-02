<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\PayoutTransaction;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\Notification\NotificationService;
use Modules\Calculator\Services\Payout\PayoutGateway;
use Modules\Calculator\Services\Payout\PayoutResult;
use Modules\Calculator\Support\TonAddress;
use Modules\Calculator\Services\Telegram\TelegramNotifications;
use RuntimeException;
use Throwable;

/**
 * Заявки на вывод средств (Фаза 3 + Фаза 4 on-chain). Создание партнёром — с холдом
 * доступного баланса через ledger; одобрение/отклонение/выплата — финансистом.
 * Суммы — целые центы. Все денежные шаги — в транзакции с холдом ledger.
 *
 * Фаза 4: payout_details трактуется как TON-адрес получателя USDT; sendOnChain
 * заменяет ручной markPaid реальной on-chain выплатой.
 */
class WithdrawalService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly PayoutGateway $payout,
        private readonly KycService $kyc,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * C1 (Block C): best-effort уведомление партнёра о смене статуса выплаты. Вызывается
     * ПОСЛЕ commit'а транзакции выплаты, обёрнуто в try/catch — НЕ влияет на бизнес-логику
     * выплаты (нельзя «откатить» on-chain из-за уведомления). Идемпотентно по dedup_key
     * (wd:<id>:<status>): повторный вызов того же перехода не задвоит уведомление.
     *
     * @param array<string,mixed> $presented результат present() (id/amount/status/reject_reason)
     */
    private function notifyPayoutStatus(int $memberId, array $presented): void
    {
        try {
            $status = (string) ($presented['status'] ?? '');
            $id = (int) ($presented['id'] ?? 0);
            if ($id <= 0 || $status === '') {
                return;
            }
            $html = TelegramNotifications::payoutStatus(
                $status,
                (string) ($presented['amount'] ?? '0.00'),
                $presented['reject_reason'] ?? null,
            );
            $this->notifications->enqueueToMember(
                $memberId,
                'payout.status',
                $html,
                'Статус выплаты',
                "payout.status:wd:{$id}:{$status}",
                ['withdrawal_id' => $id, 'status' => $status],
            );
        } catch (Throwable $e) {
            // Best-effort: уведомление не критично для выплаты. Токен бота не логируем.
        }
    }

    /** Список заявок партнёра (новые сверху). */
    public function listForMember(Member $member): array
    {
        return WithdrawalRequest::query()
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WithdrawalRequest $w) => $this->present($w))
            ->all();
    }

    /**
     * Создать заявку: валидирует сумму ≤ доступного баланса, переводит её в холд.
     * Сумма — строка/число в долларах от клиента; парсится в центы без float-потерь.
     */
    public function create(Member $member, string $amount, string $payoutDetails): array
    {
        $amountCents = $this->dollarsToCents($amount);
        if ($amountCents <= 0) {
            throw new RuntimeException('Сумма вывода должна быть больше нуля');
        }
        if (trim($payoutDetails) === '') {
            throw new RuntimeException('Укажите реквизиты для вывода');
        }
        // B7: реквизиты = mainnet TON-адрес; битая чексумма/testnet → честный 422
        // (ValidationException, а не RuntimeException→404 из guarded()). Ошибка формата,
        // пойманная здесь, стоит ноль; пойманная при ручной выплате — зависшие деньги.
        if (! TonAddress::isValid($payoutDetails)) {
            throw ValidationException::withMessages([
                'payout_details' => 'Невалидный TON-адрес: проверьте адрес кошелька (testnet-адреса не принимаются)',
            ]);
        }
        // Пороговый KYC-гейт (Фаза 4): выше порога нужен одобренный KYC.
        $this->kyc->assertCleared($member, $amountCents);

        return DB::transaction(function () use ($member, $amountCents, $payoutDetails) {
            $wallet = MemberWallet::query()->where('member_id', $member->id)->lockForUpdate()->first();
            $available = $wallet->available_cents ?? 0;
            if ($amountCents > $available) {
                throw new RuntimeException('Недостаточно средств: доступно ' . $this->centsToDecimal($available));
            }

            $w = WithdrawalRequest::query()->create([
                'member_id' => $member->id,
                'amount_cents' => $amountCents,
                'payout_details' => $payoutDetails,
                'status' => WithdrawalRequest::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);

            $this->ledger->hold($w); // available → held

            return $this->present($w);
        });
    }

    // --- Финансист (админ): очередь и статус-машина ---

    /** Очередь заявок для финансиста, опционально по статусу. С балансом партнёра (ТЗ US-4). */
    public function listForAdmin(?string $status = null): array
    {
        $items = WithdrawalRequest::query()
            ->with('payoutTransaction')
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->get();

        $wallets = MemberWallet::query()
            ->whereIn('member_id', $items->pluck('member_id')->unique())
            ->get()
            ->keyBy('member_id');

        return $items->map(function (WithdrawalRequest $w) use ($wallets) {
            $wallet = $wallets->get($w->member_id);

            return $this->present($w) + [
                'member_id' => $w->member_id,
                // On-chain выплата (если уже отправлена) — хэш транзакции и её статус для прозрачности.
                'tx_hash' => $w->payoutTransaction?->tx_hash,
                'payout_status' => $w->payoutTransaction?->status,
                // Баланс партнёра — финансист решает по «протухшей» заявке (held не подлежит clawback).
                'member_balance' => [
                    'available' => $this->centsToDecimal($wallet->available_cents ?? 0),
                    'held' => $this->centsToDecimal($wallet->held_cents ?? 0),
                    'clawback_debt' => $this->centsToDecimal($wallet->clawback_debt_cents ?? 0),
                ],
            ];
        })->all();
    }

    /** requested → approved (средства остаются в холде до выплаты). */
    public function approve(int $id, Member $finance): array
    {
        [$result, $memberId] = $this->transitionWithMember($id, [WithdrawalRequest::STATUS_REQUESTED], function (WithdrawalRequest $w) use ($finance) {
            $w->status = WithdrawalRequest::STATUS_APPROVED;
            $w->decided_by = $finance->id;
            $w->decided_at = now();
            $w->save();
        });
        $this->notifyPayoutStatus($memberId, $result); // best-effort, после commit

        return $result;
    }

    /** requested → rejected (возврат холда в доступный баланс). */
    public function reject(int $id, Member $finance, string $reason): array
    {
        [$result, $memberId] = $this->transitionWithMember($id, [WithdrawalRequest::STATUS_REQUESTED], function (WithdrawalRequest $w) use ($finance, $reason) {
            $this->ledger->releaseHold($w);
            $w->status = WithdrawalRequest::STATUS_REJECTED;
            $w->decided_by = $finance->id;
            $w->decided_at = now();
            $w->reject_reason = $reason;
            $w->save();
        });
        $this->notifyPayoutStatus($memberId, $result); // best-effort, после commit

        return $result;
    }

    /** approved → paid (фиксация ручной выплаты: held → выплачено). */
    public function markPaid(int $id): array
    {
        [$result, $memberId] = $this->transitionWithMember($id, [WithdrawalRequest::STATUS_APPROVED], function (WithdrawalRequest $w) {
            $this->ledger->markPaid($w);
            $w->status = WithdrawalRequest::STATUS_PAID;
            $w->paid_at = now();
            $w->save();
        });
        $this->notifyPayoutStatus($memberId, $result); // best-effort, после commit

        return $result;
    }

    /**
     * approved → paid через on-chain выплату USDT (Фаза 4). Создаёт payout_transaction,
     * отправляет средства, при успехе фиксирует held → выплачено и пишет tx_hash. При
     * неуспехе шлюза (result=failed) возвращает холд и переводит заявку в cancelled.
     * Если драйвер бросает (не сконфигурирован) — транзакция откатывается, заявка цела.
     */
    public function sendOnChain(int $id): array
    {
        // Контролируемый отказ шлюза (result=failed) ПЕРСИСТИМ (возврат холда + cancelled),
        // транзакция коммитится, и лишь затем бросаем — иначе откат стёр бы отмену. Если же
        // драйвер сам бросит (не сконфигурирован/сеть) — транзакция откатится, заявка цела.
        $outcome = DB::transaction(function () use ($id) {
            $w = WithdrawalRequest::query()->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($w->status !== WithdrawalRequest::STATUS_APPROVED) {
                throw new InvalidArgumentException("Недопустимый переход из статуса «{$w->status}»");
            }
            $address = trim((string) $w->payout_details);
            if ($address === '') {
                throw new RuntimeException('Не указан адрес выплаты');
            }

            $tx = PayoutTransaction::query()->create([
                'withdrawal_request_id' => $w->id,
                'to_address' => $address,
                'amount_cents' => $w->amount_cents,
                'status' => PayoutTransaction::STATUS_QUEUED,
            ]);

            $result = $this->payout->send($address, $w->amount_cents, "wd:{$w->id}");

            if (!$result->isSuccess()) {
                $tx->status = PayoutTransaction::STATUS_FAILED;
                $tx->error = $result->error;
                $tx->save();
                $this->ledger->releaseHold($w); // возврат холда в доступный баланс
                $w->status = WithdrawalRequest::STATUS_CANCELLED;
                $w->reject_reason = 'on-chain failed: ' . ($result->error ?? '');
                $w->decided_at = now();
                $w->save();

                return ['ok' => false, 'error' => $result->error ?? ''];
            }

            $tx->tx_hash = $result->txHash;
            $tx->status = $result->status;
            $tx->save();

            // Фиксируем выплату ТОЛЬКО при подтверждении сети. На broadcast средства
            // остаются в холде, заявка — approved; финализирует/откатывает poll-команда
            // (reconcilePayout) по факту confirmed/failed. Иначе broadcast→failed терял бы холд.
            if ($result->status === PayoutResult::CONFIRMED) {
                $this->ledger->markPaid($w); // held → company_payouts_paid
                $w->status = WithdrawalRequest::STATUS_PAID;
                $w->paid_at = now();
                $w->save();
            }

            return ['ok' => true, 'data' => $this->present($w) + [
                'tx_hash' => $result->txHash,
                'payout_status' => $result->status,
            ], 'member_id' => (int) $w->member_id];
        });

        if (!$outcome['ok']) {
            // Заявка отменена возвратом холда — уведомим best-effort после commit.
            // member_id не вернулся в провальной ветке; достаём отдельным lookup-ом.
            $wId = $id;
            $w = WithdrawalRequest::query()->find($wId);
            if ($w !== null) {
                $this->notifyPayoutStatus((int) $w->member_id, $this->present($w));
            }
            throw new RuntimeException('Выплата on-chain не удалась: ' . $outcome['error']);
        }

        // best-effort, после commit; уведомляем только если заявка финализирована (paid).
        if (($outcome['data']['status'] ?? null) === WithdrawalRequest::STATUS_PAID) {
            $this->notifyPayoutStatus($outcome['member_id'], $outcome['data']);
        }

        return $outcome['data'];
    }

    /**
     * Финализация broadcast-выплаты по данным сети (poll-команда). confirmed → held
     * списан как выплачено, заявка paid; failed → возврат холда, заявка cancelled.
     * Идемпотентно и под блокировкой: трогает только записи в статусе broadcast/approved.
     */
    public function reconcilePayout(int $payoutTxId, string $chainStatus): void
    {
        // Возвращаем [memberId, presented] для финализированных заявок, чтобы уведомить
        // партнёра best-effort ПОСЛЕ commit'а (не внутри транзакции). null = нет перехода.
        $notify = DB::transaction(function () use ($payoutTxId, $chainStatus) {
            $tx = PayoutTransaction::query()->where('id', $payoutTxId)->lockForUpdate()->first();
            if ($tx === null || $tx->status !== PayoutTransaction::STATUS_BROADCAST) {
                return null;
            }
            $w = WithdrawalRequest::query()->where('id', $tx->withdrawal_request_id)->lockForUpdate()->first();

            if ($chainStatus === PayoutResult::CONFIRMED) {
                $tx->status = PayoutTransaction::STATUS_CONFIRMED;
                $tx->save();
                if ($w !== null && $w->status === WithdrawalRequest::STATUS_APPROVED) {
                    $this->ledger->markPaid($w);
                    $w->status = WithdrawalRequest::STATUS_PAID;
                    $w->paid_at = now();
                    $w->save();

                    return [(int) $w->member_id, $this->present($w)];
                }
            } elseif ($chainStatus === PayoutResult::FAILED) {
                $tx->status = PayoutTransaction::STATUS_FAILED;
                $tx->error = 'on-chain failed (poll)';
                $tx->save();
                if ($w !== null && $w->status === WithdrawalRequest::STATUS_APPROVED) {
                    $this->ledger->releaseHold($w);
                    $w->status = WithdrawalRequest::STATUS_CANCELLED;
                    $w->reject_reason = 'on-chain failed (poll)';
                    $w->decided_at = now();
                    $w->save();

                    return [(int) $w->member_id, $this->present($w)];
                }
            }

            return null;
        });

        if ($notify !== null) {
            $this->notifyPayoutStatus($notify[0], $notify[1]); // best-effort, после commit
        }
    }

    /** approved → cancelled (выплата не состоялась: возврат холда). */
    public function cancel(int $id, Member $finance): array
    {
        [$result, $memberId] = $this->transitionWithMember($id, [WithdrawalRequest::STATUS_APPROVED], function (WithdrawalRequest $w) use ($finance) {
            $this->ledger->releaseHold($w);
            $w->status = WithdrawalRequest::STATUS_CANCELLED;
            $w->decided_by = $finance->id;
            $w->decided_at = now();
            $w->save();
        });
        $this->notifyPayoutStatus($memberId, $result); // best-effort, после commit

        return $result;
    }

    /**
     * Атомарный переход статуса под блокировкой строки. Бросает 422, если текущий
     * статус не входит в допустимые исходные (UI не должен показывать такие действия).
     *
     * @param array<int,string> $allowedFrom
     */
    private function transition(int $id, array $allowedFrom, callable $apply): array
    {
        return $this->transitionWithMember($id, $allowedFrom, $apply)[0];
    }

    /**
     * Как transition(), но также возвращает member_id (для best-effort уведомления после
     * commit'а). Возврат: [presented, memberId].
     *
     * @param array<int,string> $allowedFrom
     * @return array{0:array<string,mixed>,1:int}
     */
    private function transitionWithMember(int $id, array $allowedFrom, callable $apply): array
    {
        return DB::transaction(function () use ($id, $allowedFrom, $apply) {
            $w = WithdrawalRequest::query()->where('id', $id)->lockForUpdate()->firstOrFail();
            if (!in_array($w->status, $allowedFrom, true)) {
                throw new InvalidArgumentException("Недопустимый переход из статуса «{$w->status}»");
            }
            $apply($w);

            return [$this->present($w), (int) $w->member_id];
        });
    }

    /** @return array<string,mixed> */
    private function present(WithdrawalRequest $w): array
    {
        return [
            'id' => $w->id,
            'amount' => $this->centsToDecimal($w->amount_cents),
            'status' => $w->status,
            'payout_details' => $w->payout_details,
            'reject_reason' => $w->reject_reason,
            'requested_at' => $w->requested_at?->toIso8601String(),
            'decided_at' => $w->decided_at?->toIso8601String(),
            'paid_at' => $w->paid_at?->toIso8601String(),
        ];
    }

    /** Строка долларов "D.CC" → целые центы без float-потерь. */
    private function dollarsToCents(string $value): int
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Некорректная сумма');
        }
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '0');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return (int) $int * 100 + (int) $frac;
    }

    /** Центы → строка decimal "D.CC" без float (парность с dollarsToCents). */
    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
