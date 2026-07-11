<?php

namespace Modules\Calculator\V2\Services\Ledger;

use Illuminate\Support\Str;
use Modules\Calculator\Models\LedgerEntry;

/**
 * mh-full-plan T02: низкоуровневый постер двойной записи поверх ТОЙ ЖЕ таблицы
 * ledger_entries. Копия механики V1 LedgerService::post()/leg()/assertBalanced()/
 * alreadyPosted(), но с публичным API и БЕЗ привязки к V1-кэшу member_wallets
 * (V1 LedgerService не трогаем — его приватный saveWallet жёстко связан с V1).
 *
 * Деньги — ТОЛЬКО integer USD-центы. Каждая операция = группа проводок с общим
 * tx_id, Σdebit = Σcredit, идемпотентность — unique idempotency_key на первой
 * проводке группы. Вызывается ВНУТРИ DB::transaction.
 *
 * Новые V2-типы счетов (схема ledger_entries не меняется, account_type string(32)):
 * member_os_available / member_os_held / member_ns / member_bs_available /
 * member_bs_held / company_expired_balance / company_pool_retained.
 */
class LedgerPostingV2Service
{
    // Субсчета участника (V2)
    public const ACC_OS_AVAILABLE = 'member_os_available';
    public const ACC_OS_HELD = 'member_os_held';
    public const ACC_NS = 'member_ns';
    public const ACC_BS_AVAILABLE = 'member_bs_available';
    public const ACC_BS_HELD = 'member_bs_held';
    // Системные счета компании (V2)
    public const ACC_EXPIRED_BALANCE = 'company_expired_balance';
    /** Sink дельты калибровки 60%-пула (amendments, fixloop): raw − paid при factor < 10000. */
    public const ACC_POOL_RETAINED = 'company_pool_retained';

    public const DR = 'debit';
    public const CR = 'credit';

    /** @return array<string,mixed> */
    public function leg(?int $memberId, string $account, string $direction, int $amountCents): array
    {
        return [
            'member_id' => $memberId,
            'account_type' => $account,
            'direction' => $direction,
            'amount_cents' => $amountCents,
        ];
    }

    public function alreadyPosted(string $idempotencyKey): bool
    {
        return LedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * Записать сбалансированную группу проводок. Возвращает tx_id группы
     * (для трассировки в v2_wallet_lot_consumptions).
     *
     * @param array<int,array<string,mixed>> $legs
     */
    public function post(array $legs, string $sourceType, ?int $sourceId, string $idempotencyKey, array $meta = []): string
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
                // Ключ идемпотентности — на первой проводке группы (unique).
                'idempotency_key' => $i === 0 ? $idempotencyKey : null,
                'meta' => $meta === [] ? null : json_encode($meta),
                'created_at' => $now,
            ];
        }

        LedgerEntry::query()->insert($rows);

        return $txId;
    }

    /** @param array<int,array<string,mixed>> $legs */
    private function assertBalanced(array $legs): void
    {
        if ($legs === []) {
            throw new \DomainException('Ledger transaction must contain at least one leg');
        }
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
