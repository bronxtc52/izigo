<?php

namespace Modules\Calculator\V2\Services\Status;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Domain\Tier\TierResolver;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\TierHistory;
use Modules\Calculator\V2\Services\Volume\ActivationLockGuard;

/**
 * T05: тир контракта по НАКОПЛЕННОМУ personal PV (BR-TIER-001, решение роадмапа):
 * personal_pv_total пересчитывается из immutable-снапшотов покупок участника
 * (v2_order_volume_snapshots.member_id = покупатель) на момент $at — ИДЕМПОТЕНТНО
 * (повтор runFor того же заказа не удваивает PV). При ПОВЫШЕНИИ тира — append
 * v2_tier_history (tier_before/tier_after) + подъём current_tier. Тир НЕ понижается
 * (сравнение по ordinal каталога), даже если реверс уменьшит базу (DEC-020/DEC-027).
 */
class TierService
{
    public function __construct(
        private readonly ActivationLockGuard $lockGuard,
        private readonly TierResolver $resolver,
    ) {
    }

    /**
     * Пересчитать personal PV из снапшотов и повысить тир, если достигнут порог.
     * $orderId — атрибуция строки истории (source_order_id) к заказу-триггеру.
     */
    public function applyPaidOrder(int $memberId, int $orderId, CarbonImmutable $paidAt, PolicyV2 $policy): void
    {
        $this->lockGuard->assertLockHeld();

        $state = PartnerState::query()->firstOrCreate(
            ['member_id' => $memberId],
            ['state' => PartnerState::STATE_NONE, 'personal_pv_total' => '0'],
        );

        $newTotal = $this->personalPvAsOf($memberId, $paidAt);
        $state->personal_pv_total = $newTotal;

        $tiers = $policy->tiers();
        $resolved = $this->resolver->resolve($tiers, $newTotal);
        $newTierCode = $resolved?->code;

        $currentOrdinal = $this->resolver->ordinal($tiers, $state->current_tier);
        $newOrdinal = $this->resolver->ordinal($tiers, $newTierCode);

        if ($newTierCode !== null && $newOrdinal > $currentOrdinal) {
            // insertOrIgnore-семантика через unique(member_id, tier): повторный проход
            // того же повышения не плодит строк (идемпотентность по заказу).
            TierHistory::query()->firstOrCreate(
                ['member_id' => $memberId, 'tier' => $newTierCode],
                [
                    'tier_before' => $state->current_tier,
                    'basis_personal_pv' => $newTotal,
                    'source_order_id' => $orderId,
                    'policy_version_id' => $policy->versionId(),
                    'effective_at' => $paidAt,
                    'created_at' => now(),
                ],
            );
            $state->current_tier = $newTierCode;
        }

        $state->save();
    }

    /** Σ PV снапшотов покупок участника с paid_at <= $at (personal PV «за весь период»). */
    public function personalPvAsOf(int $memberId, \DateTimeInterface $at): string
    {
        $row = DB::table('v2_order_volume_snapshots')
            ->where('member_id', $memberId)
            ->where('paid_at', '<=', $at)
            ->selectRaw('COALESCE(SUM(pv), 0) AS total')
            ->first();

        return (string) ($row->total ?? '0');
    }

    /**
     * as-of чтение тира из истории (контракт для T07): тир с последним effective_at
     * среди строк с effective_at <= $at.
     *
     * Корректность опирается на ИНВАРИАНТ МОНОТОННОСТИ (architect, W2 review): тир
     * повышается только вверх, а effective_at не убывает с ростом ordinal тира
     * (высший тир достигнут не раньше низшего) — иначе «последний по времени» вернул бы
     * младший тир (тир не понижается, DEC-020/027). Инвариант ОБЕСПЕЧЕН, не допущение:
     * (1) append-once — unique(member_id, tier) (миграция 100100) + firstOrCreate в
     * applyPaidOrder делают строку тира immutable; (2) upward-only — строка пишется лишь
     * при newOrdinal > currentOrdinal, current_tier только растёт. Коды тиров append-only
     * и не ремапятся между версиями политики => резолв version-agnostic (architect-2).
     */
    public function tierAsOf(int $memberId, \DateTimeInterface $at): ?string
    {
        return DB::table('v2_tier_history')
            ->where('member_id', $memberId)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->value('tier');
    }
}
