<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Modules\Calculator\V2\Services\PolicyVersionService;

/**
 * Хелперы feature-тестов T09 (глобальный бонус): активная политика (с опц.
 * override секции global_pool под тесты max_shares/cap), сид members по
 * sponsor-дереву, рангов, PV/BV-снапшотов заказов и расчётных периодов.
 * Деньги — integer USD-центы, PV — decimal-строки.
 */
trait SeedsV2GlobalBonus
{
    private int $gbSeq = 0;
    private ?int $gbPlacementLast = null;
    private ?int $gbProductId = null;

    /** Активировать политику MH V2 (retro), опц. переопределив секцию global_pool. */
    protected function activateGlobalBonusPolicy(array $globalOverrides = []): PolicyV2
    {
        $doc = DefaultPolicyConfig::doc();
        if ($globalOverrides !== []) {
            $doc['global_pool'] = array_merge($doc['global_pool'], $globalOverrides);
        }

        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-usd-t09-test', $doc, null);
        $service->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);

        return app(PolicyVersionResolver::class)->current();
    }

    protected function underActivationLock(callable $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            app(ActivationService::class)->acquireActivationLock();

            return $fn();
        });
    }

    /** Создать участника (линейный placement-чейн для single-root); sponsor_id — свободно. */
    protected function makeMember(?int $sponsorId = null): int
    {
        $this->gbSeq++;
        $tgId = 900000 + $this->gbSeq;

        $attrs = [
            'telegram_id' => $tgId,
            'name' => "GB{$this->gbSeq}",
            'ref_code' => 'GB' . str_pad((string) $this->gbSeq, 6, '0', STR_PAD_LEFT),
            'sponsor_id' => $sponsorId,
            'status' => 'active',
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($this->gbPlacementLast !== null) {
            // Валидный бинарный placement: левый потомок предыдущего (unique(parent,position) ок).
            $attrs['parent_id'] = $this->gbPlacementLast;
            $attrs['position'] = 'left';
        }

        $id = (int) DB::table('members')->insertGetId($attrs);
        $this->gbPlacementLast = $id;

        return $id;
    }

    /** Достигнутый ранг участника (v2_rank_history, «ранг навсегда»). */
    protected function seedRank(int $memberId, StatusCode $rank, ?CarbonImmutable $at = null): void
    {
        DB::table('v2_rank_history')->insertOrIgnore([
            'member_id' => $memberId,
            'rank_code' => $rank->value,
            'rank_ordinal' => $rank->ordinal(),
            'achieved_at' => $at ?? CarbonImmutable::parse('2026-02-01', 'UTC'),
            'evaluation_id' => null,
            'policy_version_id' => 1,
            'created_at' => now(),
        ]);
    }

    /** PV/BV-снапшот оплаченного заказа участника (order+item создаются под FK). */
    protected function seedSnapshot(int $memberId, string $pv, int $bvCents, CarbonImmutable $paidAt): void
    {
        $orderId = (int) DB::table('orders')->insertGetId([
            'member_id' => $memberId,
            'package_id' => 1,
            'total_usdt_cents' => $bvCents,
            'total_pv' => 0,
            'status' => 'paid',
            'created_at' => $paidAt,
            'updated_at' => $paidAt,
        ]);
        $itemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'product_id' => $this->gbProduct(),
            'qty' => 1,
            'unit_price_usdt_cents' => $bvCents,
            'pv' => 0,
            'name_snapshot' => 'GB item',
        ]);
        DB::table('v2_order_volume_snapshots')->insert([
            'order_id' => $orderId,
            'order_item_id' => $itemId,
            'member_id' => $memberId,
            'pv' => $pv,
            'bv_usd_cents' => $bvCents,
            'policy_version_id' => 1,
            'paid_at' => $paidAt,
            'created_at' => $paidAt,
        ]);
    }

    /** Расчётный период по коду (создаёт строку v2_calc_periods, если её нет). */
    protected function ensurePeriod(string $code): CalcPeriod
    {
        return app(PeriodService::class)->ensureByCode($code);
    }

    protected function enableGlobalBonusFlag(): void
    {
        app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->set('mh_v2_global_bonus', true);
    }

    /**
     * Прямой сид ФИНАЛЬНОГО месяца с member-аллокациями заданного final_cents в
     * director-пуле (для тестов квартальной выплаты — эмулирует уже посчитанный/
     * откалиброванный месяц). Возвращает id GlobalBonusMonth.
     *
     * @param array<int,int> $memberFinalCents member_id => final_cents
     */
    protected function seedFinalMonth(CalcPeriod $period, array $memberFinalCents, int $policyVersionId): int
    {
        $monthId = (int) DB::table('v2_global_bonus_months')->insertGetId([
            'month_period_id' => $period->id,
            'policy_version_id' => $policyVersionId,
            'global_bv_cents' => 0,
            'status' => \Modules\Calculator\V2\Models\GlobalBonusMonth::STATUS_FINAL,
            'computed_at' => now(),
            'finalized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poolId = (int) DB::table('v2_global_bonus_pools')->insertGetId([
            'global_bonus_month_id' => $monthId,
            'pool_rank' => \Modules\Calculator\V2\Models\GlobalBonusPool::RANK_DIRECTOR,
            'rate_bps' => 100,
            'pool_amount_cents' => array_sum($memberFinalCents),
            'total_shares' => count($memberFinalCents),
            'allocated_cents' => array_sum($memberFinalCents),
            'unallocated_cents' => 0,
            'unallocated_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($memberFinalCents as $memberId => $finalCents) {
            DB::table('v2_global_bonus_allocations')->insert([
                'global_bonus_month_id' => $monthId,
                'pool_id' => $poolId,
                'member_id' => $memberId,
                'kind' => \Modules\Calculator\V2\Models\GlobalBonusAllocation::KIND_MEMBER,
                'shares' => 1,
                'raw_cents' => $finalCents,
                'capped_cents' => $finalCents,
                'final_cents' => $finalCents,
                'status' => \Modules\Calculator\V2\Models\GlobalBonusAllocation::STATUS_ACCRUED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $monthId;
    }

    /** Пометить период закрытым (для предикатов закрытия квартала). */
    protected function markPeriodClosed(CalcPeriod $period): void
    {
        $period->update([
            'status' => CalcPeriod::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => 'test',
        ]);
    }

    private function gbProduct(): int
    {
        if ($this->gbProductId === null) {
            $this->gbProductId = (int) DB::table('products')->insertGetId([
                'name' => 'GB Product',
                'price_usdt_cents' => 10000,
                'pv' => 0,
                'package_id' => 1,
                'is_active' => true,
                'sort' => 1,
                'sku' => 'GB-SKU',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->gbProductId;
    }
}
