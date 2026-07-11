<?php

namespace Modules\Calculator\V2\Services\Wallet;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotConsumptionV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\NsToOsTransfer;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\Exceptions\InsufficientAccountBalanceException;

/**
 * mh-full-plan T02: ядро субсчетов ОС/НС/БС поверх double-entry ledger.
 * Реализует контракты волны LedgerV2 (credit/debit) и NsToOsTransfer
 * (executeForCalibratedMonth, MF-4/MF-6 — команда и расписание у T04).
 *
 * Инварианты:
 *  - деньги — ТОЛЬКО integer USD-центы; каждая операция — сбалансированная группа
 *    проводок (LedgerPostingV2Service) + обновление кэша v2_member_accounts под
 *    lockForUpdate (паттерн V1 lockWallet: insertOrIgnore + повторная выборка);
 *  - ОС/БС — лоты (v2_wallet_lots), потребление EARLIEST_EXPIRY_FIRST, при равных
 *    expires_at — id ASC (DEC-015); лоты с expires_at IS NULL не сгорают (MF-9)
 *    и потребляются последними;
 *  - НС — плоский транзитный субсчёт без лотов (BR-ACC-001/003);
 *  - все методы идемпотентны по ledger idempotency_key (повтор = no-op).
 *
 * V1 (LedgerService / member_wallets) не изменяется вообще.
 */
class WalletAccountsV2Service implements LedgerV2, NsToOsTransfer
{
    /** Соответствие субсчёт → [колонка кэша available, тип счёта ledger]. */
    private const SUBACCOUNTS = [
        self::SUBACCOUNT_OS => ['os_available_cents', LedgerPostingV2Service::ACC_OS_AVAILABLE],
        self::SUBACCOUNT_NS => ['ns_cents', LedgerPostingV2Service::ACC_NS],
        self::SUBACCOUNT_BS => ['bs_available_cents', LedgerPostingV2Service::ACC_BS_AVAILABLE],
    ];

    public function __construct(
        private readonly LedgerPostingV2Service $poster,
        private readonly AccountsPolicyV2 $policy,
    ) {
    }

    // ------------------------------------------------------------------
    // Контракт LedgerV2
    // ------------------------------------------------------------------

    /**
     * Кредит субсчёта новым лотом (ОС/БС) или плоско (НС). Сигнатура контракта LedgerV2:
     * $sourceType/$sourceId — тип и id источника (бонус T06-T10), попадают в лот и в
     * проводку. $expiresAt = null → лот НЕ сгорает (award-лоты, MF-9). $accrualMonth
     * (только НС) — месяц атрибуции начисления 'YYYY-MM' для месячного перевода НС→ОС
     * (ревью W1 MF-3); по умолчанию — месяц now() UTC, штампуется в meta.ns_month.
     */
    public function credit(
        int $memberId,
        string $subaccount,
        int $amountCents,
        string $idempotencyKey,
        ?\DateTimeInterface $expiresAt = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $accrualMonth = null,
    ): void {
        [$column, $ledgerAccount] = $this->subaccount($subaccount);
        if ($amountCents <= 0) {
            throw new \DomainException('Credit amount must be positive');
        }
        if ($accrualMonth !== null && $subaccount !== self::SUBACCOUNT_NS) {
            throw new \DomainException('accrualMonth применим только к НС (месячная атрибуция перевода НС→ОС, MF-3)');
        }
        if ($accrualMonth !== null && ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $accrualMonth)) {
            throw new \DomainException("Некорректный месяц атрибуции НС: {$accrualMonth} (ожидается YYYY-MM)");
        }
        $nsMonth = $subaccount === self::SUBACCOUNT_NS
            ? ($accrualMonth ?? now('UTC')->format('Y-m'))
            : null;

        DB::transaction(function () use (
            $memberId, $subaccount, $amountCents, $idempotencyKey, $expiresAt, $sourceType, $sourceId, $column, $ledgerAccount, $nsMonth
        ) {
            // Идемпотентность — ПОД локом счёта (nice-to-have NTH-4 ревью W1: гонка
            // дублей до лока приводила ко второму падению по unique вместо no-op).
            $account = $this->lockAccount($memberId);
            if ($this->poster->alreadyPosted($idempotencyKey)) {
                return;
            }

            $meta = ['subaccount' => $subaccount, 'v2_source' => $sourceType];
            if ($nsMonth !== null) {
                $meta['ns_month'] = $nsMonth; // атрибуция месяца для перевода НС→ОС (MF-3)
            }
            $this->poster->post([
                $this->poster->leg(null, LedgerService::ACC_COMMISSION_EXPENSE, LedgerPostingV2Service::DR, $amountCents),
                $this->poster->leg($memberId, $ledgerAccount, LedgerPostingV2Service::CR, $amountCents),
            ], 'bonus_v2', $sourceId, $idempotencyKey, $meta);

            if ($subaccount !== self::SUBACCOUNT_NS) {
                WalletLotV2::query()->create([
                    'member_id' => $memberId,
                    'account' => $subaccount,
                    'amount_cents' => $amountCents,
                    'available_cents' => $amountCents,
                    'earned_at' => now(),
                    'expires_at' => $expiresAt, // null = не сгорает (MF-9)
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'status' => WalletLotV2::STATUS_ACTIVE,
                    'idempotency_key' => $idempotencyKey,
                ]);
            }

            $account->{$column} += $amountCents;
            $this->saveAccount($account);
        });
    }

    /**
     * Дебет субсчёта: списание с лотов EARLIEST_EXPIRY_FIRST (ОС/БС) либо плоско (НС).
     * Dr member_X / Cr company_sales_revenue. Недостаточно средств →
     * InsufficientAccountBalanceException.
     */
    public function debit(
        int $memberId,
        string $subaccount,
        int $amountCents,
        string $idempotencyKey,
    ): void {
        [$column, $ledgerAccount] = $this->subaccount($subaccount);
        if ($amountCents <= 0) {
            throw new \DomainException('Debit amount must be positive');
        }

        DB::transaction(function () use ($memberId, $subaccount, $amountCents, $idempotencyKey, $column, $ledgerAccount) {
            // Идемпотентность — ПОД локом счёта (NTH-4, симметрично credit()).
            $account = $this->lockAccount($memberId);
            if ($this->poster->alreadyPosted($idempotencyKey)) {
                return;
            }
            if ($account->{$column} < $amountCents) {
                throw new InsufficientAccountBalanceException(
                    "Недостаточно средств на субсчёте {$subaccount}: доступно {$account->{$column}}, требуется {$amountCents}",
                );
            }

            $txId = $this->poster->post([
                $this->poster->leg($memberId, $ledgerAccount, LedgerPostingV2Service::DR, $amountCents),
                $this->poster->leg(null, LedgerService::ACC_SALES_REVENUE, LedgerPostingV2Service::CR, $amountCents),
            ], 'acct_charge', null, $idempotencyKey, ['subaccount' => $subaccount]);

            if ($subaccount !== self::SUBACCOUNT_NS) {
                $this->consumeLots($memberId, $subaccount, $amountCents, WalletLotConsumptionV2::REASON_DEBIT, $txId);
            }

            $account->{$column} -= $amountCents;
            $this->saveAccount($account);
        });
    }

    // ------------------------------------------------------------------
    // Контракт NsToOsTransfer (MF-4: после месячной калибровки; команда — T04)
    // ------------------------------------------------------------------

    public function executeForCalibratedMonth(string $month, int $factorBps): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new \DomainException("Некорректный месяц перевода НС→ОС: {$month} (ожидается YYYY-MM)");
        }
        if ($factorBps < 0 || $factorBps > 10000) {
            throw new \DomainException("factor_bps вне диапазона 0..10000: {$factorBps}");
        }

        // Ревью W1 MF-3: переводим ТОЛЬКО НС-начисления откалиброванного месяца —
        // по атрибуции meta.ns_month кредит-проводок НС (штампует credit()), а не весь
        // плоский баланс. Начисления соседних месяцев остаются на НС до СВОЕЙ калибровки
        // (иначе уехали бы в ОС под чужим factor_bps).
        $monthSums = \Modules\Calculator\Models\LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_NS)
            ->where('direction', LedgerPostingV2Service::CR)
            ->whereRaw("(meta->>'ns_month') = ?", [$month])
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(amount_cents) AS cents')
            ->pluck('cents', 'member_id');

        foreach ($monthSums as $memberId => $monthCents) {
            DB::transaction(function () use ($memberId, $monthCents, $month, $factorBps) {
                // DEC-019: ключ на окно (месяц), без holiday shift; повтор джоба = no-op.
                $key = "v2:ns_transfer:{$memberId}:{$month}";
                if ($this->poster->alreadyPosted($key)) {
                    return;
                }
                $account = $this->lockAccount((int) $memberId);
                // Кэш — жёсткий потолок: НС не должен уходить в минус, если часть
                // атрибутированных месяцу денег уже списана иным путём (дрейф).
                $raw = min((int) $monthCents, $account->ns_cents);
                if ($raw < (int) $monthCents) {
                    \Illuminate\Support\Facades\Log::warning(
                        'V2 НС→ОС: атрибуция месяца превышает плоский НС — перевод ограничен балансом',
                        ['member_id' => (int) $memberId, 'month' => $month, 'month_cents' => (int) $monthCents, 'ns_cents' => $account->ns_cents]
                    );
                }
                if ($raw <= 0) {
                    return; // гонка: НС уже пуст
                }

                // MF-1/2/4: paid = intdiv(raw * factor, 10000); дельта калибровки — в sink
                // company_pool_retained (fixloop), двойная запись сходится.
                $paid = intdiv($raw * $factorBps, 10000);
                $delta = $raw - $paid;

                $legs = [$this->poster->leg((int) $memberId, LedgerPostingV2Service::ACC_NS, LedgerPostingV2Service::DR, $raw)];
                if ($paid > 0) {
                    $legs[] = $this->poster->leg((int) $memberId, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::CR, $paid);
                }
                if ($delta > 0) {
                    $legs[] = $this->poster->leg(null, LedgerPostingV2Service::ACC_POOL_RETAINED, LedgerPostingV2Service::CR, $delta);
                }
                $this->poster->post($legs, 'ns_transfer', null, $key, [
                    'month' => $month, 'factor_bps' => $factorBps, 'raw_cents' => $raw,
                ]);

                if ($paid > 0) {
                    // BR-ACC-001: годовой срок ОС-лота — с даты зачисления на ОС (даты перевода).
                    $now = now();
                    WalletLotV2::query()->create([
                        'member_id' => (int) $memberId,
                        'account' => WalletLotV2::ACCOUNT_OS,
                        'amount_cents' => $paid,
                        'available_cents' => $paid,
                        'earned_at' => $now,
                        'expires_at' => $now->copy()->addDays($this->policy->osLotLifetimeDays($now)),
                        'source_type' => 'ns_transfer',
                        'status' => WalletLotV2::STATUS_ACTIVE,
                        'idempotency_key' => $key,
                    ]);
                }

                $account->ns_cents -= $raw;
                $account->os_available_cents += $paid;
                $this->saveAccount($account);
            });
        }
    }

    // ------------------------------------------------------------------
    // Сгорание лотов (джоб mh2:lots-expire, ежедневно)
    // ------------------------------------------------------------------

    /**
     * Обработать истёкшие лоты на момент $asOf (EARLIEST_EXPIRY_FIRST):
     *  - ОС-лот: остаток → новый БС-лот (origin_lot_id, годовой срок БС с даты переноса,
     *    BR-ACC-004) + проводка Dr member_os_available / Cr member_bs_available;
     *  - БС-лот: остаток → Dr member_bs_available / Cr company_expired_balance (forfeit);
     *  - лоты с expires_at IS NULL пропускаются (award-лоты не сгорают, MF-9);
     *  - идемпотентно: ключ v2:lot_expiry:{lot_id}, повторный прогон — no-op.
     *
     * @return int число обработанных лотов
     */
    public function expireLots(?\DateTimeInterface $asOf = null): int
    {
        $asOf = $asOf === null ? now() : \Illuminate\Support\Carbon::instance($asOf);

        $lotIds = WalletLotV2::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $asOf)
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->orderBy('expires_at')->orderBy('id')
            ->pluck('id');

        $processed = 0;
        foreach ($lotIds as $lotId) {
            $processed += (int) DB::transaction(function () use ($lotId, $asOf) {
                $peek = WalletLotV2::query()->find($lotId);
                if ($peek === null) {
                    return false;
                }
                // Единый порядок локов «счёт → лот» (как в consumeLots под lockAccount
                // вызывающего), иначе дедлок джоба сгорания с конкурентным резервом/дебетом.
                $account = $this->lockAccount($peek->member_id);
                $lot = WalletLotV2::query()->where('id', $lotId)->lockForUpdate()->first();
                if ($lot === null || $lot->status !== WalletLotV2::STATUS_ACTIVE) {
                    return false;
                }
                if ($lot->available_cents <= 0) {
                    // Лот потреблён под ноль (в т.ч. в день сгорания) — денег нет, проводок нет.
                    $lot->status = WalletLotV2::STATUS_EXHAUSTED;
                    $lot->save();

                    return false;
                }

                $key = "v2:lot_expiry:{$lot->id}";
                if ($this->poster->alreadyPosted($key)) {
                    return false;
                }

                $rest = $lot->available_cents;

                if ($lot->account === WalletLotV2::ACCOUNT_OS) {
                    // Просроченный остаток ОС переносится на БС новым лотом.
                    $txId = $this->poster->post([
                        $this->poster->leg($lot->member_id, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::DR, $rest),
                        $this->poster->leg($lot->member_id, LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::CR, $rest),
                    ], 'lot_expiry', $lot->id, $key, ['from' => 'os', 'to' => 'bs']);

                    WalletLotV2::query()->create([
                        'member_id' => $lot->member_id,
                        'account' => WalletLotV2::ACCOUNT_BS,
                        'amount_cents' => $rest,
                        'available_cents' => $rest,
                        'earned_at' => $asOf,
                        'expires_at' => $asOf->copy()->addDays($this->policy->bsLotLifetimeDays($asOf)),
                        'source_type' => 'lot_expiry',
                        'source_id' => $lot->id,
                        'origin_lot_id' => $lot->id,
                        'status' => WalletLotV2::STATUS_ACTIVE,
                        'idempotency_key' => $key,
                    ]);

                    $this->traceLot($lot->id, $rest, WalletLotConsumptionV2::REASON_EXPIRY_TRANSFER, $txId);
                    $lot->status = WalletLotV2::STATUS_TRANSFERRED;
                    $account->os_available_cents -= $rest;
                    $account->bs_available_cents += $rest;
                } else {
                    // Просроченный остаток БС аннулируется (forfeit).
                    $txId = $this->poster->post([
                        $this->poster->leg($lot->member_id, LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::DR, $rest),
                        $this->poster->leg(null, LedgerPostingV2Service::ACC_EXPIRED_BALANCE, LedgerPostingV2Service::CR, $rest),
                    ], 'lot_expiry', $lot->id, $key, ['from' => 'bs', 'to' => 'forfeit']);

                    $this->traceLot($lot->id, $rest, WalletLotConsumptionV2::REASON_EXPIRY_ANNUL, $txId);
                    $lot->status = WalletLotV2::STATUS_EXPIRED;
                    $account->bs_available_cents -= $rest;
                }

                $lot->available_cents = 0;
                $lot->save();
                $this->saveAccount($account);

                return true;
            });
        }

        return $processed;
    }

    // ------------------------------------------------------------------
    // Цикл вывода средств — ТОЛЬКО с ОС (переключение WithdrawalService — T15)
    // ------------------------------------------------------------------

    /** Заявка на вывод: os_available → os_held (лоты потребляются earliest-expiry-first). */
    public function holdForWithdrawal(WithdrawalRequest $w): void
    {
        DB::transaction(function () use ($w) {
            $key = "v2:wd:{$w->id}:hold";
            if ($this->poster->alreadyPosted($key)) {
                return;
            }
            $account = $this->lockAccount($w->member_id);
            if ($account->os_available_cents < $w->amount_cents) {
                throw new InsufficientAccountBalanceException(
                    "Недостаточно средств на ОС: доступно {$account->os_available_cents}, требуется {$w->amount_cents}",
                );
            }

            $txId = $this->poster->post([
                $this->poster->leg($w->member_id, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::DR, $w->amount_cents),
                $this->poster->leg($w->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::CR, $w->amount_cents),
            ], 'withdrawal_v2', $w->id, $key);

            $this->consumeLots($w->member_id, WalletLotV2::ACCOUNT_OS, $w->amount_cents,
                WalletLotConsumptionV2::REASON_WITHDRAWAL_HOLD, $txId, null, $w->id);

            $account->os_available_cents -= $w->amount_cents;
            $account->os_held_cents += $w->amount_cents;
            $this->saveAccount($account);
        });
    }

    /** Отклонение/отмена вывода: os_held → os_available, средства возвращаются в те же лоты. */
    public function releaseWithdrawalHold(WithdrawalRequest $w): void
    {
        DB::transaction(function () use ($w) {
            $key = "v2:wd:{$w->id}:release";
            if ($this->poster->alreadyPosted($key)) {
                return;
            }
            $account = $this->lockAccount($w->member_id);

            $txId = $this->poster->post([
                $this->poster->leg($w->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::DR, $w->amount_cents),
                $this->poster->leg($w->member_id, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::CR, $w->amount_cents),
            ], 'withdrawal_v2', $w->id, $key);

            $restored = $this->restoreLots(
                WalletLotConsumptionV2::REASON_WITHDRAWAL_HOLD,
                WalletLotConsumptionV2::REASON_REVERSAL,
                $txId,
                withdrawalRequestId: $w->id,
            );
            if ($restored !== $w->amount_cents) {
                throw new \DomainException(
                    "Возврат холда вывода {$w->id}: восстановлено {$restored} ≠ {$w->amount_cents}",
                );
            }

            $account->os_held_cents -= $w->amount_cents;
            $account->os_available_cents += $w->amount_cents;
            $this->saveAccount($account);
        });
    }

    /** Выплачено вручную: os_held → company_payouts_paid (деньги ушли наружу). */
    public function markWithdrawalPaid(WithdrawalRequest $w): void
    {
        DB::transaction(function () use ($w) {
            $key = "v2:wd:{$w->id}:paid";
            if ($this->poster->alreadyPosted($key)) {
                return;
            }
            $account = $this->lockAccount($w->member_id);

            $this->poster->post([
                $this->poster->leg($w->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::DR, $w->amount_cents),
                $this->poster->leg(null, LedgerService::ACC_PAYOUTS_PAID, LedgerPostingV2Service::CR, $w->amount_cents),
            ], 'withdrawal_v2', $w->id, $key);

            $account->os_held_cents -= $w->amount_cents;
            $this->saveAccount($account);
        });
    }

    // ------------------------------------------------------------------
    // Внутренний API V2-кошелька (используется OrderAccountPaymentService)
    // ------------------------------------------------------------------

    /** Кэш субсчетов под блокировкой строки (создаёт нулевой при первом обращении). */
    public function lockAccount(int $memberId): MemberAccountV2
    {
        $account = MemberAccountV2::query()->where('member_id', $memberId)->lockForUpdate()->first();
        if ($account === null) {
            MemberAccountV2::query()->insertOrIgnore([
                'member_id' => $memberId,
                'currency' => 'USD',
                'updated_at' => now(),
            ]);
            $account = MemberAccountV2::query()->where('member_id', $memberId)->lockForUpdate()->firstOrFail();
        }

        return $account;
    }

    public function saveAccount(MemberAccountV2 $account): void
    {
        $account->updated_at = now();
        $account->save();
    }

    /**
     * Списать $cents с активных лотов участника EARLIEST_EXPIRY_FIRST (равные expires_at →
     * id ASC; бессрочные — последними). Вызывать под уже взятым lockAccount; баланс кэша
     * проверяет вызывающий. Пишет трассировку в v2_wallet_lot_consumptions.
     */
    public function consumeLots(
        int $memberId,
        string $account,
        int $cents,
        string $reason,
        ?string $txId = null,
        ?int $reservationId = null,
        ?int $withdrawalRequestId = null,
    ): void {
        $remaining = $cents;
        $lots = WalletLotV2::query()
            ->where('member_id', $memberId)
            ->where('account', $account)
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->where('available_cents', '>', 0)
            ->orderByRaw('expires_at ASC NULLS LAST, id ASC')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, $lot->available_cents);
            $lot->available_cents -= $take;
            if ($lot->available_cents === 0) {
                $lot->status = WalletLotV2::STATUS_EXHAUSTED;
            }
            $lot->save();

            WalletLotConsumptionV2::query()->create([
                'lot_id' => $lot->id,
                'amount_cents' => $take,
                'reason' => $reason,
                'tx_id' => $txId,
                'reservation_id' => $reservationId,
                'withdrawal_request_id' => $withdrawalRequestId,
                'created_at' => now(),
            ]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            // Дрейф кэш vs лоты — деньги «есть», а лотов нет. Жёсткий стоп (rollback).
            throw new InsufficientAccountBalanceException(
                "Лоты субсчёта {$account} участника {$memberId} не покрывают {$cents} (не хватило {$remaining})",
            );
        }
    }

    /**
     * Вернуть в лоты всё, что было списано с признаком ($outReason, reservation/withdrawal id).
     * Компенсация: available_cents растёт, exhausted-лот снова active (если истёк —
     * подберёт ближайший expireLots). Возвращает суммарно восстановленные центы.
     */
    public function restoreLots(
        string $outReason,
        string $backReason,
        ?string $txId = null,
        ?int $reservationId = null,
        ?int $withdrawalRequestId = null,
    ): int {
        $consumed = WalletLotConsumptionV2::query()
            ->where('reason', $outReason)
            ->when($reservationId !== null, fn ($q) => $q->where('reservation_id', $reservationId))
            ->when($withdrawalRequestId !== null, fn ($q) => $q->where('withdrawal_request_id', $withdrawalRequestId))
            ->get()
            ->groupBy('lot_id');

        $total = 0;
        foreach ($consumed as $lotId => $rows) {
            $back = $rows->sum('amount_cents');
            $lot = WalletLotV2::query()->where('id', $lotId)->lockForUpdate()->firstOrFail();
            $lot->available_cents += $back;
            if ($lot->status === WalletLotV2::STATUS_EXHAUSTED) {
                $lot->status = WalletLotV2::STATUS_ACTIVE;
            }
            $lot->save();

            WalletLotConsumptionV2::query()->create([
                'lot_id' => $lot->id,
                'amount_cents' => $back,
                'reason' => $backReason,
                'tx_id' => $txId,
                'reservation_id' => $reservationId,
                'withdrawal_request_id' => $withdrawalRequestId,
                'created_at' => now(),
            ]);
            $total += $back;
        }

        return $total;
    }

    /** Трассировочная строка движения по лоту (для операций сгорания). */
    private function traceLot(int $lotId, int $cents, string $reason, string $txId): void
    {
        WalletLotConsumptionV2::query()->create([
            'lot_id' => $lotId,
            'amount_cents' => $cents,
            'reason' => $reason,
            'tx_id' => $txId,
            'created_at' => now(),
        ]);
    }

    /** @return array{0:string,1:string} [колонка кэша, тип счёта ledger] */
    private function subaccount(string $subaccount): array
    {
        if (! isset(self::SUBACCOUNTS[$subaccount])) {
            throw new \DomainException("Неизвестный субсчёт: {$subaccount}");
        }

        return self::SUBACCOUNTS[$subaccount];
    }
}
