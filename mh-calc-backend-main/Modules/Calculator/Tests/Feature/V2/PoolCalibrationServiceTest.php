<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Pool;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\PoolCalibration;
use Modules\Calculator\V2\Models\PoolCalibrationItem;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Pool\PoolCalibrationService;
use Tests\TestCase;

/**
 * T11 [ДЕНЬГИ]: оркестратор 60%-калибровки месяца. Числитель = структурная-после-капов
 * + месячный глобальный; реферальная/лидерский/награды ВНЕ числителя (MF-1/2, MF-W3-3).
 * factor_bps коммитится в v2_pool_calibrations (контракт reader'а T08/T04); глобальный
 * final_cents перезаписывается; структурная — проекция (постит T02). BR-POOL-002 supersede.
 */
class PoolCalibrationServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;
    use SeedsV2Pool;

    private const MONTH = '2026-03';

    private function service(): PoolCalibrationService
    {
        return app(PoolCalibrationService::class);
    }

    private function monthPeriod(): CalcPeriod
    {
        return $this->ensurePeriod(self::MONTH);
    }

    private function paidAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::MONTH . '-10 12:00:00', 'UTC');
    }

    public function testWorkedExampleBase10000Sum10000GivesFactor6000(): void
    {
        $this->activateGlobalBonusPolicy();
        $member = $this->makeMember();
        $period = $this->monthPeriod();

        // База BV месяца = 10000; структурная-после-капов = 10000; глобального нет.
        $this->seedSnapshot($member, '0', 10000, $this->paidAt());
        $this->seedStructureBonus($member, self::MONTH, 10000);

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(6000, $cal->factor_bps);          // worked example
        $this->assertSame(10000, $cal->base_bv_cents);
        $this->assertSame(6000, $cal->pool_cap_cents);
        $this->assertSame(10000, $cal->structure_after_caps_cents);
        $this->assertSame(0, $cal->global_after_caps_cents);
        $this->assertSame(10000, $cal->total_after_caps_cents);
        $this->assertSame(6000, $cal->scaled_total_cents);
        $this->assertSame(4000, $cal->company_retained_cents); // удержано компанией
        $this->assertSame(PoolCalibration::STATUS_COMMITTED, $cal->status);

        $item = $cal->items()->where('bonus_kind', PoolCalibrationItem::KIND_STRUCTURE)->sole();
        $this->assertSame($member, $item->member_id);
        $this->assertSame(6000, $item->calibrated_cents);
        $this->assertSame(4000, $item->retained_cents);
        $this->assertSame(PoolCalibrationItem::STATE_PROJECTED, $item->state); // постит T02

        // Контракт reader'а T08/T04.
        $this->assertSame(6000, app(PoolCalibrationReader::class)->factorBpsFor(self::MONTH));
    }

    public function testGlobalFinalCentsScaledAndUnallocatedUntouched(): void
    {
        $this->activateGlobalBonusPolicy();
        $m1 = $this->makeMember();
        $m2 = $this->makeMember();
        $period = $this->monthPeriod();

        // base_bv 10000 → cap 6000; структурной нет; глобальный member-total 12000 → factor 5000.
        $this->seedSnapshot($m1, '0', 10000, $this->paidAt());
        [, $allocIds] = $this->seedDraftGlobalMonth($period, [$m1 => 6000, $m2 => 6000], unallocatedCapped: 1000);

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(5000, $cal->factor_bps);
        $this->assertSame(12000, $cal->global_after_caps_cents);
        $this->assertSame(6000, $cal->scaled_total_cents); // Σ final = intdiv(12000*5000/10000)
        $this->assertLessThanOrEqual($cal->pool_cap_cents, $cal->scaled_total_cents);

        // final_cents member-аллокаций перезаписаны фактором.
        $this->assertSame(3000, (int) GlobalBonusAllocation::query()->find($allocIds[$m1])->final_cents);
        $this->assertSame(3000, (int) GlobalBonusAllocation::query()->find($allocIds[$m2])->final_cents);

        // UNALLOCATED-строка НЕ калибруется (уже деньги компании).
        $unalloc = GlobalBonusAllocation::query()
            ->where('kind', GlobalBonusAllocation::KIND_UNALLOCATED)->sole();
        $this->assertSame(1000, (int) $unalloc->final_cents);

        // items(global) в состоянии applied.
        $this->assertSame(2, $cal->items()->where('bonus_kind', PoolCalibrationItem::KIND_GLOBAL)->count());
        $this->assertSame(
            PoolCalibrationItem::STATE_APPLIED,
            $cal->items()->where('bonus_kind', PoolCalibrationItem::KIND_GLOBAL)->first()->state,
        );
    }

    public function testReferralIsInformationalNotInNumerator(): void
    {
        $this->activateGlobalBonusPolicy();
        $member = $this->makeMember();
        $source = $this->makeMember();
        $period = $this->monthPeriod();

        $this->seedSnapshot($member, '0', 10000, $this->paidAt());
        $this->seedStructureBonus($member, self::MONTH, 10000);
        // Реферальная 5000 — в отчёт, но НЕ в числитель (MF-W3-3).
        $this->seedReferralReward($member, $source, 5000, $this->paidAt());

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(5000, $cal->referral_gross_cents);
        $this->assertSame(10000, $cal->total_after_caps_cents); // референс НЕ добавлен
        $this->assertSame(6000, $cal->factor_bps);              // не 4000 (был бы при referral в числителе)
        // Реферальная не порождает items (вне пула).
        $this->assertSame(0, $cal->items()->whereNotIn('bonus_kind', [
            PoolCalibrationItem::KIND_STRUCTURE, PoolCalibrationItem::KIND_GLOBAL,
        ])->count());
    }

    public function testReversedStructureExcludedFromNumerator(): void
    {
        $this->activateGlobalBonusPolicy();
        $member = $this->makeMember();
        $period = $this->monthPeriod();

        $this->seedSnapshot($member, '0', 10000, $this->paidAt());
        $this->seedStructureBonus($member, self::MONTH, 4000, StructureBonus::STATUS_POSTED, 'H1');
        $this->seedStructureBonus($member, self::MONTH, 6000, StructureBonus::STATUS_REVERSED, 'H2');

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(4000, $cal->structure_after_caps_cents); // reversed 6000 исключён
    }

    public function testZeroBaseBvGivesZeroFactorAllRetained(): void
    {
        $this->activateGlobalBonusPolicy();
        $member = $this->makeMember();
        $period = $this->monthPeriod();

        // Нет BV-снапшотов → base_bv=0, при ненулевой структурной → factor 0.
        $this->seedStructureBonus($member, self::MONTH, 10000);

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(0, $cal->base_bv_cents);
        $this->assertSame(0, $cal->factor_bps);
        $this->assertSame(0, $cal->scaled_total_cents);
        $this->assertSame(10000, $cal->company_retained_cents);
    }

    public function testRecalibrateSupersedesPriorCommitted(): void
    {
        $this->activateGlobalBonusPolicy();
        $member = $this->makeMember();
        $period = $this->monthPeriod();
        $this->seedSnapshot($member, '0', 10000, $this->paidAt());
        $this->seedStructureBonus($member, self::MONTH, 10000);

        $first = $this->service()->calibrateMonth($period);
        $second = $this->service()->calibrateMonth($period);

        $this->assertSame(1, $first->run_version);
        $this->assertSame(2, $second->run_version);
        // Ровно одна committed (partial unique index); прежняя superseded (BR-POOL-002).
        $this->assertSame(1, PoolCalibration::query()->where('period_id', $period->id)
            ->where('status', PoolCalibration::STATUS_COMMITTED)->count());
        $this->assertSame(PoolCalibration::STATUS_SUPERSEDED, $first->refresh()->status);
        // Reader отдаёт актуальную версию.
        $this->assertSame(6000, app(PoolCalibrationReader::class)->factorBpsFor(self::MONTH));
    }

    public function testEmptyMonthCommitsFullFactorNoItems(): void
    {
        $this->activateGlobalBonusPolicy();
        $period = $this->monthPeriod();

        $cal = $this->service()->calibrateMonth($period);

        $this->assertSame(10000, $cal->factor_bps); // числитель 0 → f=1
        $this->assertSame(0, $cal->total_after_caps_cents);
        $this->assertSame(0, $cal->items()->count());
    }
}
