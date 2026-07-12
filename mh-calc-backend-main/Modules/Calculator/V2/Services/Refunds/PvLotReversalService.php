<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Services\Volume\PvLotIngestService;

/**
 * T12: сторно PV-лотов возврата. Делегирует реверс несматченного остатка задаче T03
 * (reverseUnmatchedLotsForOrder — контракт заморожен, менять нельзя, только
 * использовать) и фиксирует шаги в v2_reversal_actions для explainability.
 *
 *  - несматченный остаток лота → pv_reversed (state reversed/exhausted), free-остатки
 *    веток корректны, будущий матчинг его не увидит;
 *  - уже сматченный PV: лот НЕ возвращается в начало FIFO-очереди (компенсационная
 *    политика, план §956), матч помечен reversal_required_at (T03) — денежный каскад
 *    структурной/лидерской по нему идёт корректирующими проводками (BonusReversalService).
 *
 * Вызывать под advisory-lock оркестратора (RefundService держит ACTIVATION_LOCK);
 * T03 внутри проверяет assertLockHeld().
 */
class PvLotReversalService
{
    public function __construct(
        private readonly PvLotIngestService $ingest,
    ) {
    }

    /**
     * @return array{reversed_lot_ids:int[],affected_match_ids:int[],matched:bool}
     */
    public function reverseForReturn(OrderReturn $return): array
    {
        $res = $this->ingest->reverseUnmatchedLotsForOrder(
            $return->order_id,
            "refund#{$return->id}: {$return->reason}",
        );

        foreach ($res['reversed_lot_ids'] as $lotId) {
            ReversalAction::query()->firstOrCreate(
                ['idempotency_key' => "v2:reversal:{$return->id}:pvlot:{$lotId}"],
                [
                    'return_id' => $return->id,
                    'action_type' => ReversalAction::TYPE_PV_LOT_REVERSAL,
                    'target_type' => 'pv_lot',
                    'target_id' => $lotId,
                    'status' => ReversalAction::STATUS_POSTED,
                ],
            );
        }

        foreach ($res['affected_match_ids'] as $matchId) {
            ReversalAction::query()->firstOrCreate(
                ['idempotency_key' => "v2:reversal:{$return->id}:match:{$matchId}"],
                [
                    'return_id' => $return->id,
                    'action_type' => ReversalAction::TYPE_MATCH_COMPENSATION,
                    'target_type' => 'binary_match',
                    'target_id' => $matchId,
                    // денежный каскад по сматченному PV — корректирующими проводками (pending)
                    'status' => ReversalAction::STATUS_PENDING,
                ],
            );
        }

        return [
            'reversed_lot_ids' => $res['reversed_lot_ids'],
            'affected_match_ids' => $res['affected_match_ids'],
            'matched' => $res['affected_match_ids'] !== [],
        ];
    }
}
