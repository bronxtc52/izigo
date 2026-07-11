<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Models\PvLot;

/**
 * T03: проекция ролей веток — детерминированный recompute из v2_pv_lots
 * (single source of truth; строку можно снести и восстановить).
 * free = Σ pv_available; lifetime = Σ pv_original − Σ pv_reversed (реверс
 * выпадает из истории объёма). large_side — по lifetime, tie => NULL.
 * Вызывается после каждого инжеста/матчинга/reversal.
 */
class BranchStatsService
{
    public function recompute(int $memberId): void
    {
        $rows = PvLot::query()
            ->where('owner_member_id', $memberId)
            ->groupBy('side')
            ->selectRaw(
                'side, '
                . 'COALESCE(SUM(pv_available), 0) AS free_pv, '
                . 'COALESCE(SUM(pv_original), 0) - COALESCE(SUM(pv_reversed), 0) AS lifetime_pv'
            )
            ->get()
            ->keyBy('side');

        $leftFree = (string) ($rows[PvLot::SIDE_LEFT]->free_pv ?? '0');
        $rightFree = (string) ($rows[PvLot::SIDE_RIGHT]->free_pv ?? '0');
        $leftLife = (string) ($rows[PvLot::SIDE_LEFT]->lifetime_pv ?? '0');
        $rightLife = (string) ($rows[PvLot::SIDE_RIGHT]->lifetime_pv ?? '0');

        $cmp = bccomp($leftLife, $rightLife, 6);
        $largeSide = $cmp === 0 ? null : ($cmp > 0 ? PvLot::SIDE_LEFT : PvLot::SIDE_RIGHT);
        $smallLifetime = $cmp > 0 ? $rightLife : $leftLife;

        DB::table('v2_member_branch_stats')->upsert(
            [[
                'member_id' => $memberId,
                'left_free_pv' => $leftFree,
                'right_free_pv' => $rightFree,
                'left_lifetime_pv' => $leftLife,
                'right_lifetime_pv' => $rightLife,
                'large_side' => $largeSide,
                'small_branch_lifetime_pv' => $smallLifetime,
                'recomputed_at' => now(),
            ]],
            ['member_id'],
            [
                'left_free_pv', 'right_free_pv', 'left_lifetime_pv', 'right_lifetime_pv',
                'large_side', 'small_branch_lifetime_pv', 'recomputed_at',
            ]
        );
    }

    /** @param int[] $memberIds */
    public function recomputeMany(array $memberIds): void
    {
        foreach (array_unique($memberIds) as $memberId) {
            $this->recompute((int) $memberId);
        }
    }
}
