<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\ReversalAction;

/**
 * T12: пере-оценка квалификаций при возврате — через СНАПШОТ, БЕЗ отзыва достижений.
 *
 * Инварианты «ранг навсегда» (DEC-020/DEC-027, dec-triage §95-99):
 *  - достигнутый РАНГ НЕ отзывается (v2_rank_history не трогаем);
 *  - квалификационные НАГРАДЫ T10 НЕ сторнируются (v2_award_entitlements не трогаем) —
 *    reversal-контур обходит source_type='award';
 *  - ТИР НЕ понижается (DEC-010; v2_tier_history не трогаем).
 *
 * Возврат уменьшает только PV-БАЗУ будущих апгрейдов: PV-лоты уже реверснуты T03
 * (PvLotReversalService), поэтому будущая квалификация читает уменьшенный live-объём
 * автоматически. Здесь фиксируются только АУДИТ-записи (tier_basis_adjust +
 * qualification_note) — деньги/статусы не трогаются.
 */
class RequalificationService
{
    public function recordForReturn(OrderReturn $return): void
    {
        // База тира будущих апгрейдов уменьшается на returned_pv (эффект — через
        // реверснутые PV-лоты T03; здесь только провенанс).
        ReversalAction::query()->firstOrCreate(
            ['idempotency_key' => "v2:reversal:{$return->id}:tier_basis"],
            [
                'return_id' => $return->id,
                'action_type' => ReversalAction::TYPE_TIER_BASIS_ADJUST,
                'amount_pv' => '-' . (string) $return->returned_pv,
                'snapshot_json' => [
                    'note' => 'tier_basis_pv уменьшен на returned_pv; тир НЕ понижается (DEC-010)',
                    'returned_pv' => (string) $return->returned_pv,
                ],
                'status' => ReversalAction::STATUS_POSTED,
            ],
        );

        // Явная фиксация неотзываемости достижений (ранг/награда навсегда).
        ReversalAction::query()->firstOrCreate(
            ['idempotency_key' => "v2:reversal:{$return->id}:qual_note"],
            [
                'return_id' => $return->id,
                'action_type' => ReversalAction::TYPE_QUALIFICATION_NOTE,
                'snapshot_json' => [
                    'rank_reversed' => false,   // DEC-020
                    'award_reversed' => false,  // DEC-027
                    'tier_downgraded' => false, // DEC-010
                    'note' => 'Ранг навсегда; награды не отзываются; тир не понижается. '
                        . 'Уменьшается только PV-база будущих квалификаций.',
                ],
                'status' => ReversalAction::STATUS_POSTED,
            ],
        );
    }
}
