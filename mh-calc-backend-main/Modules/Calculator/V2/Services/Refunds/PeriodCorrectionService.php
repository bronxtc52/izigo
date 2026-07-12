<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\PeriodCorrection;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Services\Refunds\Exceptions\RefundConflictException;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;

/**
 * T12: корректирующие проводки закрытых периодов (v2_period_corrections, DEC-027).
 * Закрытый период НЕ переоткрывается: возврат, затронувший закрытый (и, возможно,
 * откалиброванный) период, оформляется отдельной проводкой proposed→approved→posted.
 *
 * Пост корректировки (контракт-чек W2+ №5): деньги сторнируются НАПРЯМУЮ с ОС
 * (reverseBonusCredit, при нехватке → clawback-долг), НЕ кредитуя/дебетуя НС уже
 * переведёнными (откалиброванными) месяцами. Один owner: create=propose,
 * approve/reject/post — owner (dec-triage: без four-eyes, обязателен reason + audit).
 */
class PeriodCorrectionService
{
    public function __construct(
        private readonly WalletAccountsV2Service $wallet,
    ) {
    }

    /**
     * Предложить корректировку (idempotent по ключу — повтор планирования = та же строка).
     */
    public function propose(
        OrderReturn $return,
        int $periodId,
        int $memberId,
        string $bonusType,
        int $amountCents,
        array $snapshot = [],
    ): PeriodCorrection {
        $key = "v2:corr:{$return->id}:{$periodId}:{$bonusType}:{$memberId}";

        $correction = PeriodCorrection::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'period_id' => $periodId,
                'return_id' => $return->id,
                'member_id' => $memberId,
                'bonus_type' => $bonusType,
                'amount_cents' => $amountCents,
                'status' => PeriodCorrection::STATUS_PROPOSED,
                'reason' => "refund#{$return->id}: {$return->reason}",
                'snapshot_json' => $snapshot,
            ],
        );

        ReversalAction::query()->firstOrCreate(
            ['idempotency_key' => "{$key}:action"],
            [
                'return_id' => $return->id,
                'action_type' => ReversalAction::TYPE_PERIOD_CORRECTION_PROPOSED,
                'bonus_type' => $bonusType,
                'target_type' => 'period_correction',
                'target_id' => $correction->id,
                'amount_cents' => $amountCents,
                'snapshot_json' => $snapshot,
                'status' => ReversalAction::STATUS_PENDING,
            ],
        );

        return $correction;
    }

    /** Owner утверждает корректировку: proposed → approved. */
    public function approve(int $correctionId, ?int $adminId): PeriodCorrection
    {
        return DB::transaction(function () use ($correctionId, $adminId) {
            $correction = PeriodCorrection::query()->where('id', $correctionId)->lockForUpdate()->first();
            if ($correction === null) {
                throw new RefundConflictException('Корректировка не найдена');
            }
            if ($correction->status !== PeriodCorrection::STATUS_PROPOSED) {
                throw new RefundConflictException("Корректировку нельзя утвердить из статуса {$correction->status}");
            }
            $correction->status = PeriodCorrection::STATUS_APPROVED;
            $correction->approved_by_admin_id = $adminId;
            $correction->approved_at = now();
            $correction->save();

            return $correction;
        });
    }

    /** Owner отклоняет корректировку: proposed → rejected. */
    public function reject(int $correctionId, ?int $adminId): PeriodCorrection
    {
        return DB::transaction(function () use ($correctionId, $adminId) {
            $correction = PeriodCorrection::query()->where('id', $correctionId)->lockForUpdate()->first();
            if ($correction === null) {
                throw new RefundConflictException('Корректировка не найдена');
            }
            if ($correction->status !== PeriodCorrection::STATUS_PROPOSED) {
                throw new RefundConflictException("Корректировку нельзя отклонить из статуса {$correction->status}");
            }
            $correction->status = PeriodCorrection::STATUS_REJECTED;
            $correction->approved_by_admin_id = $adminId;
            $correction->approved_at = now();
            $correction->save();

            return $correction;
        });
    }

    /**
     * Провести утверждённую корректировку (approved → posted). Идемпотентная проводка;
     * деньги сторнируются с ОС напрямую (W2+ №5 — не через уже переведённые месяцы НС).
     * Положительная корректировка (доначисление) не ожидается в контуре возвратов —
     * amount_cents отрицательный (сторно); |amount| снимается с ОС.
     */
    public function post(int $correctionId): PeriodCorrection
    {
        $correction = DB::transaction(function () use ($correctionId) {
            $c = PeriodCorrection::query()->where('id', $correctionId)->lockForUpdate()->first();
            if ($c === null) {
                throw new RefundConflictException('Корректировка не найдена');
            }
            if ($c->status === PeriodCorrection::STATUS_POSTED) {
                throw new RefundConflictException('Корректировка уже проведена');
            }
            if ($c->status !== PeriodCorrection::STATUS_APPROVED) {
                throw new RefundConflictException("Корректировку нельзя провести из статуса {$c->status}");
            }

            $key = "v2:corr_post:{$c->id}";
            $amount = (int) $c->amount_cents;
            if ($amount < 0) {
                $res = $this->wallet->reverseBonusCredit(
                    memberId: $c->member_id,
                    subaccount: LedgerV2::SUBACCOUNT_OS,
                    amountCents: abs($amount),
                    idempotencyKey: $key,
                    sourceType: "corr_{$c->bonus_type}",
                    sourceId: $c->id,
                );
                $c->ledger_tx_id = $res['tx_id'];
            }
            $c->status = PeriodCorrection::STATUS_POSTED;
            $c->save();

            ReversalAction::query()->firstOrCreate(
                ['idempotency_key' => "{$key}:action"],
                [
                    'return_id' => $c->return_id,
                    'action_type' => ReversalAction::TYPE_PERIOD_CORRECTION_PROPOSED,
                    'bonus_type' => $c->bonus_type,
                    'target_type' => 'period_correction',
                    'target_id' => $c->id,
                    'amount_cents' => $amount,
                    'ledger_tx_id' => $c->ledger_tx_id,
                    'status' => ReversalAction::STATUS_POSTED,
                ],
            );

            return $c;
        });

        return $correction;
    }
}
