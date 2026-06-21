<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\WithdrawalRequest;

/**
 * Журнал двойной записи (Фаза 3). Источник истины для денег. Все суммы — целые центы.
 *
 * Каждая операция пишет группу сбалансированных проводок (Σ debit = Σ credit) с общим tx_id
 * и обновляет денормализованный кэш баланса (member_wallets) в той же транзакции.
 * Вызывается ВНУТРИ внешней DB::transaction (активация / сервис выводов).
 *
 * Счета:
 *  - company_commission_expense / company_payouts_paid — счета компании (member_id = NULL)
 *  - member_available / member_held / member_clawback_debt — счета партнёра
 */
class LedgerService
{
    public const ACC_COMMISSION_EXPENSE = 'company_commission_expense';
    public const ACC_PAYOUTS_PAID = 'company_payouts_paid';
    public const ACC_AVAILABLE = 'member_available';
    public const ACC_HELD = 'member_held';
    public const ACC_CLAWBACK_DEBT = 'member_clawback_debt';

    public const DR = 'debit';
    public const CR = 'credit';

    /**
     * Начисление дохода узлу по дельте снимка (Δ = new − prev).
     * Δ > 0 — начисление (сначала гасит долг clawback, остаток в available).
     * Δ < 0 — коррекция вниз (списывает из available, излишек — в долг clawback).
     * Идемпотентно по ключу: повтор того же события не задваивает проводки.
     */
    public function accrual(int $memberId, int $deltaCents, ?int $eventId, string $idempotencyKey): void
    {
        if ($deltaCents === 0) {
            return;
        }
        if ($this->alreadyPosted($idempotencyKey)) {
            return;
        }

        $wallet = $this->lockWallet($memberId);
        $legs = [];

        if ($deltaCents > 0) {
            $debtPay = min($deltaCents, $wallet->clawback_debt_cents);
            $toAvailable = $deltaCents - $debtPay;

            // Расход компании растёт на всю дельту.
            $legs[] = $this->leg(null, self::ACC_COMMISSION_EXPENSE, self::DR, $deltaCents);
            if ($debtPay > 0) {
                $legs[] = $this->leg($memberId, self::ACC_CLAWBACK_DEBT, self::CR, $debtPay);
            }
            if ($toAvailable > 0) {
                $legs[] = $this->leg($memberId, self::ACC_AVAILABLE, self::CR, $toAvailable);
            }

            $wallet->clawback_debt_cents -= $debtPay;
            $wallet->available_cents += $toAvailable;
        } else {
            $y = -$deltaCents;
            $fromAvailable = min($y, $wallet->available_cents);
            $toDebt = $y - $fromAvailable;

            // Реверс расхода компании на всю величину коррекции.
            $legs[] = $this->leg(null, self::ACC_COMMISSION_EXPENSE, self::CR, $y);
            if ($fromAvailable > 0) {
                $legs[] = $this->leg($memberId, self::ACC_AVAILABLE, self::DR, $fromAvailable);
            }
            if ($toDebt > 0) {
                $legs[] = $this->leg($memberId, self::ACC_CLAWBACK_DEBT, self::DR, $toDebt);
            }

            $wallet->available_cents -= $fromAvailable;
            $wallet->clawback_debt_cents += $toDebt;
        }

        $this->post($legs, 'accrual', $eventId, $idempotencyKey, ['delta_cents' => $deltaCents]);
        $this->saveWallet($wallet);
    }

    /** Заявка на вывод: available → held. */
    public function hold(WithdrawalRequest $w): void
    {
        $key = "withdrawal:{$w->id}:hold";
        if ($this->alreadyPosted($key)) {
            return;
        }
        $wallet = $this->lockWallet($w->member_id);

        $this->post([
            $this->leg($w->member_id, self::ACC_AVAILABLE, self::DR, $w->amount_cents),
            $this->leg($w->member_id, self::ACC_HELD, self::CR, $w->amount_cents),
        ], 'withdrawal', $w->id, $key);

        $wallet->available_cents -= $w->amount_cents;
        $wallet->held_cents += $w->amount_cents;
        $this->saveWallet($wallet);
    }

    /** Отклонение/отмена: held → available (возврат средств). */
    public function releaseHold(WithdrawalRequest $w): void
    {
        $key = "withdrawal:{$w->id}:release";
        if ($this->alreadyPosted($key)) {
            return;
        }
        $wallet = $this->lockWallet($w->member_id);

        $this->post([
            $this->leg($w->member_id, self::ACC_HELD, self::DR, $w->amount_cents),
            $this->leg($w->member_id, self::ACC_AVAILABLE, self::CR, $w->amount_cents),
        ], 'withdrawal', $w->id, $key);

        $wallet->held_cents -= $w->amount_cents;
        $wallet->available_cents += $w->amount_cents;
        $this->saveWallet($wallet);
    }

    /** Выплачено вручную: held → company_payouts_paid (средства ушли наружу). */
    public function markPaid(WithdrawalRequest $w): void
    {
        $key = "withdrawal:{$w->id}:paid";
        if ($this->alreadyPosted($key)) {
            return;
        }
        $wallet = $this->lockWallet($w->member_id);

        $this->post([
            $this->leg($w->member_id, self::ACC_HELD, self::DR, $w->amount_cents),
            $this->leg(null, self::ACC_PAYOUTS_PAID, self::CR, $w->amount_cents),
        ], 'withdrawal', $w->id, $key);

        $wallet->held_cents -= $w->amount_cents;
        $this->saveWallet($wallet);
    }

    /** Кошелёк под блокировкой строки (создаёт пустой при первом обращении). */
    private function lockWallet(int $memberId): MemberWallet
    {
        $wallet = MemberWallet::query()->where('member_id', $memberId)->lockForUpdate()->first();
        if ($wallet === null) {
            // insertOrIgnore + повторная выборка под локом — без гонок на уникальном member_id.
            MemberWallet::query()->insertOrIgnore([
                'member_id' => $memberId,
                'available_cents' => 0,
                'held_cents' => 0,
                'clawback_debt_cents' => 0,
                'currency' => 'USD',
                'updated_at' => now(),
            ]);
            $wallet = MemberWallet::query()->where('member_id', $memberId)->lockForUpdate()->firstOrFail();
        }

        return $wallet;
    }

    private function saveWallet(MemberWallet $wallet): void
    {
        $wallet->updated_at = now();
        $wallet->save();
    }

    /** @param array<int,array<string,mixed>> $legs */
    private function post(array $legs, string $sourceType, ?int $sourceId, string $idempotencyKey, array $meta = []): void
    {
        $this->assertBalanced($legs);

        $txId = (string) Str::uuid();
        $now = now();
        $rows = [];
        foreach ($legs as $i => $leg) {
            $rows[] = [
                'tx_id' => $txId,
                'member_id' => $leg['member_id'],
                'account_type' => $leg['account_type'],
                'direction' => $leg['direction'],
                'amount_cents' => $leg['amount_cents'],
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                // Ключ идемпотентности вешаем на первую проводку группы (unique);
                // повторная операция отсекается ещё в alreadyPosted().
                'idempotency_key' => $i === 0 ? $idempotencyKey : null,
                'meta' => $meta === [] ? null : json_encode($meta),
                'created_at' => $now,
            ];
        }

        LedgerEntry::query()->insert($rows);
    }

    private function alreadyPosted(string $idempotencyKey): bool
    {
        return LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /** @return array<string,mixed> */
    private function leg(?int $memberId, string $account, string $direction, int $amountCents): array
    {
        return [
            'member_id' => $memberId,
            'account_type' => $account,
            'direction' => $direction,
            'amount_cents' => $amountCents,
        ];
    }

    /** @param array<int,array<string,mixed>> $legs */
    private function assertBalanced(array $legs): void
    {
        $debit = 0;
        $credit = 0;
        foreach ($legs as $leg) {
            if ($leg['amount_cents'] <= 0) {
                throw new \DomainException('Ledger leg amount must be positive');
            }
            $leg['direction'] === self::DR ? $debit += $leg['amount_cents'] : $credit += $leg['amount_cents'];
        }
        if ($debit !== $credit) {
            throw new \DomainException("Unbalanced ledger transaction: debit {$debit} != credit {$credit}");
        }
    }
}
