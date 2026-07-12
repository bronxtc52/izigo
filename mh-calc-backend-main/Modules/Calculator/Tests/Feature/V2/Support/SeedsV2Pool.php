<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPool;
use Modules\Calculator\V2\Models\StructureBonus;

/**
 * Хелперы feature-тестов T11 (60%-калибровка): прямой сид входов числителя —
 * строк структурной премии (после капов, по accrual_month), DRAFT-месяца
 * глобального бонуса с member-аллокациями и реферальных выплат (информационно).
 * Дополняет SeedsV2GlobalBonus (makeMember/seedSnapshot/ensurePeriod/seedRank).
 */
trait SeedsV2Pool
{
    /** Строка структурной премии за половину месяца (после капов). */
    protected function seedStructureBonus(
        int $memberId,
        string $monthCode,
        int $afterCapCents,
        string $status = StructureBonus::STATUS_POSTED,
        string $half = 'H1',
    ): int {
        $period = app(\Modules\Calculator\V2\Services\Periods\PeriodService::class)
            ->ensureByCode("{$monthCode}-{$half}");

        return (int) DB::table('v2_structure_bonuses')->insertGetId([
            'period_id' => $period->id,
            'member_id' => $memberId,
            'policy_version_id' => 1,
            'rank_code' => 'CONSULTANT',
            'rate_bps' => 500,
            'matched_pv' => '0',
            'matched_bv_cents' => $afterCapCents,
            'gross_cents' => $afterCapCents,
            'after_cap_cents' => $afterCapCents,
            'net_cents' => $afterCapCents,
            'accrual_month' => $monthCode,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * DRAFT-месяц глобального бонуса с member-аллокациями (capped) и опц. UNALLOCATED.
     * Возвращает [monthId, [memberId => allocationId]].
     *
     * @param array<int,int> $memberCapped member_id => capped_cents
     * @return array{0:int,1:array<int,int>}
     */
    protected function seedDraftGlobalMonth(CalcPeriod $monthPeriod, array $memberCapped, int $unallocatedCapped = 0): array
    {
        $monthId = (int) DB::table('v2_global_bonus_months')->insertGetId([
            'month_period_id' => $monthPeriod->id,
            'policy_version_id' => 1,
            'global_bv_cents' => 0,
            'status' => GlobalBonusMonth::STATUS_DRAFT,
            'computed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poolId = (int) DB::table('v2_global_bonus_pools')->insertGetId([
            'global_bonus_month_id' => $monthId,
            'pool_rank' => GlobalBonusPool::RANK_DIRECTOR,
            'rate_bps' => 100,
            'pool_amount_cents' => array_sum($memberCapped) + $unallocatedCapped,
            'total_shares' => count($memberCapped),
            'allocated_cents' => array_sum($memberCapped),
            'unallocated_cents' => $unallocatedCapped,
            'unallocated_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allocIds = [];
        foreach ($memberCapped as $memberId => $capped) {
            $allocIds[$memberId] = (int) DB::table('v2_global_bonus_allocations')->insertGetId([
                'global_bonus_month_id' => $monthId,
                'pool_id' => $poolId,
                'member_id' => $memberId,
                'kind' => GlobalBonusAllocation::KIND_MEMBER,
                'shares' => 1,
                'raw_cents' => $capped,
                'capped_cents' => $capped,
                'final_cents' => $capped,
                'status' => GlobalBonusAllocation::STATUS_ACCRUED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($unallocatedCapped > 0) {
            DB::table('v2_global_bonus_allocations')->insert([
                'global_bonus_month_id' => $monthId,
                'pool_id' => $poolId,
                'member_id' => null,
                'kind' => GlobalBonusAllocation::KIND_UNALLOCATED,
                'shares' => 0,
                'raw_cents' => $unallocatedCapped,
                'capped_cents' => $unallocatedCapped,
                'final_cents' => $unallocatedCapped,
                'status' => GlobalBonusAllocation::STATUS_ACCRUED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$monthId, $allocIds];
    }

    /** Реферальная выплата (информационно — вне 60%-пула). */
    protected function seedReferralReward(int $beneficiaryId, int $sourceId, int $grossCents, CarbonImmutable $paidAt): void
    {
        DB::table('v2_referral_rewards')->insert([
            'order_id' => $this->seedPaidOrderId($sourceId, $grossCents, $paidAt),
            'source_member_id' => $sourceId,
            'beneficiary_member_id' => $beneficiaryId,
            'depth' => 1,
            'tier_snapshot' => 'START',
            'rate_bps' => 1000,
            'base_bv_cents' => $grossCents,
            'gross_cents' => $grossCents,
            'net_cents' => $grossCents,
            'policy_version_id' => 1,
            'status' => 'posted',
            'ledger_idempotency_key' => "v2:referral:test:{$beneficiaryId}:{$sourceId}:" . $paidAt->timestamp,
            'paid_at' => $paidAt,
            'explain' => json_encode(['seed' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPaidOrderId(int $memberId, int $cents, CarbonImmutable $paidAt): int
    {
        return (int) DB::table('orders')->insertGetId([
            'member_id' => $memberId,
            'package_id' => 1,
            'total_usdt_cents' => $cents,
            'total_pv' => 0,
            'status' => 'paid',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
    }
}
