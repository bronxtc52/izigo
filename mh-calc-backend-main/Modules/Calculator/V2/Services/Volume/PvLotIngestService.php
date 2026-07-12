<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Models\PvLot;

/**
 * T03: инжест PV-лотов по оплаченному заказу + ручной reversal несматченного.
 *
 * Лоты раскладываются ВСЕМ бинарным предкам покупателя (DEC-055 — все binary
 * descendants, включая spillover; предки — из members.path/ltree, sponsor_id
 * не участвует). Сторона = position ребёнка-предка на пути к покупателю.
 * Идемпотентность — insertOrIgnore по UNIQUE(origin_order_item_id, owner, side)
 * (AT-IDEM-001). Перемещение узла админом существующие лоты НЕ перевешивает
 * (provenance неизменен).
 */
class PvLotIngestService
{
    public function __construct(
        private readonly BranchStatsService $branchStats,
        private readonly ActivationLockGuard $lockGuard,
    ) {
    }

    /**
     * Создать лоты по снапшотам заказа. Возвращает id предков-владельцев,
     * чьи branch-stats надо пересчитать.
     *
     * @param Collection<int, OrderVolumeSnapshot> $snapshots
     * @return int[] owner_member_id затронутых предков
     */
    public function createLotsForPaidOrder(Collection $snapshots): array
    {
        if ($snapshots->isEmpty()) {
            return [];
        }

        $buyerId = (int) $snapshots->first()->member_id;
        $ancestorSides = $this->ancestorSides($buyerId);
        if ($ancestorSides === []) {
            return []; // покупатель — корень (или вне дерева): лоты складывать некому
        }

        foreach ($snapshots as $snapshot) {
            foreach ($ancestorSides as $ownerId => $side) {
                DB::table('v2_pv_lots')->insertOrIgnore([
                    'owner_member_id' => $ownerId,
                    'side' => $side,
                    'buyer_member_id' => $buyerId,
                    'origin_order_id' => $snapshot->order_id,
                    'origin_order_item_id' => $snapshot->order_item_id,
                    'pv_original' => $snapshot->pv,
                    'pv_available' => $snapshot->pv,
                    'pv_matched' => 0,
                    'pv_reversed' => 0,
                    'bv_usd_cents_original' => $snapshot->bv_usd_cents,
                    'policy_version_id' => $snapshot->policy_version_id,
                    'state' => PvLot::STATE_FREE,
                    'occurred_at' => $snapshot->paid_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return array_keys($ancestorSides);
    }

    /**
     * Ручной refund: реверс НЕсматченного остатка лотов заказа.
     * Несматченный остаток каждого лота уходит в pv_reversed (state=reversed,
     * если лот вообще не матчился; иначе exhausted — история матчей неизменна).
     * Матчи, уже потребившие лоты заказа, помечаются reversal_required_at —
     * денежный каскад по ним выполняет T12, здесь ничего не удаляется.
     *
     * @return array{reversed_lot_ids: int[], affected_match_ids: int[], owner_member_ids: int[]}
     */
    public function reverseUnmatchedLotsForOrder(int $orderId, string $reason): array
    {
        // Ревью W1 MF-7 (amendments #5): reversal мутирует PV-лоты — advisory-lock
        // активаций обязан держать внешний оркестратор (возврат T12 / админ-операция).
        $this->lockGuard->assertLockHeld();

        $result = DB::transaction(function () use ($orderId, $reason) {
            $lots = PvLot::query()
                ->where('origin_order_id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $reversedLotIds = [];
            $owners = [];
            foreach ($lots as $lot) {
                if (bccomp($lot->pv_available, '0', 6) <= 0) {
                    continue; // нечего реверсить (уже exhausted/reversed)
                }
                $lot->pv_reversed = bcadd($lot->pv_reversed, $lot->pv_available, 6);
                $lot->pv_available = '0.000000';
                $lot->state = bccomp($lot->pv_matched, '0', 6) > 0
                    ? PvLot::STATE_EXHAUSTED   // часть уже сматчена — историю не переписываем
                    : PvLot::STATE_REVERSED;
                $lot->save();
                $reversedLotIds[] = $lot->id;
                $owners[$lot->owner_member_id] = true;
            }

            // Матчи, потребившие лоты этого заказа, — на каскадный reversal (T12).
            $affectedMatchIds = DB::table('v2_pv_lot_allocations')
                ->whereIn('pv_lot_id', $lots->pluck('id')->all())
                ->distinct()
                ->pluck('binary_match_id')
                ->all();
            if ($affectedMatchIds !== []) {
                BinaryMatch::query()
                    ->whereIn('id', $affectedMatchIds)
                    ->whereNull('reversal_required_at')
                    ->update([
                        'reversal_required_at' => now(),
                        'reversal_reason' => $reason,
                    ]);
                Log::warning('V2 volumes: refund заказа с уже сматченными лотами — матчи ждут каскадного reversal (T12)', [
                    'order_id' => $orderId,
                    'match_ids' => $affectedMatchIds,
                    'reason' => $reason,
                ]);
            }

            return [
                'reversed_lot_ids' => $reversedLotIds,
                'affected_match_ids' => array_map('intval', $affectedMatchIds),
                'owner_member_ids' => array_keys($owners),
            ];
        });

        $this->branchStats->recomputeMany($result['owner_member_ids']);

        return $result;
    }

    /**
     * Бинарные предки покупателя и сторона его поддерева у каждого:
     * members.path = ltree из id ('root.….buyer'); сторона у предка i —
     * position узла path[i+1] (ребёнок предка на пути к покупателю).
     *
     * @return array<int, string> owner_member_id => 'left'|'right'
     */
    private function ancestorSides(int $buyerId): array
    {
        $path = (string) (Member::query()->where('id', $buyerId)->value('path') ?? '');
        if ($path === '') {
            return [];
        }

        $labels = array_map('intval', explode('.', $path));
        if (count($labels) < 2 || end($labels) !== $buyerId) {
            return []; // корень либо рассинхрон path — лоты не раскладываем
        }

        $positions = Member::query()
            ->whereIn('id', $labels)
            ->pluck('position', 'id');

        $sides = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $child = $labels[$i + 1];
            $side = $positions[$child] ?? null;
            if ($side !== PvLot::SIDE_LEFT && $side !== PvLot::SIDE_RIGHT) {
                Log::error('V2 volumes: узел на пути без валидной position — лот предку пропущен', [
                    'buyer_id' => $buyerId,
                    'ancestor_id' => $labels[$i],
                    'child_id' => $child,
                ]);
                continue;
            }
            $sides[$labels[$i]] = $side;
        }

        return $sides;
    }
}
