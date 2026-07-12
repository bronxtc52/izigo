<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Pool;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\LeadershipBonusLine;
use Modules\Calculator\V2\Models\PoolCalibration;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Tests\TestCase;

/**
 * MF-W4-1 [ДЕНЬГИ, integration]: стык T11→T08 на боевом month-close пайплайне.
 *
 * Регрессия ревью W4: лидерский бонус (T08) обязан считаться от ФАКТИЧЕСКИ
 * ВЫПЛАЧЕННОЙ структурной премии (после капов И 60%-калибровки, DEC-029), а не от
 * некалиброванного after_cap. Прогоняем РЕАЛЬНУЮ цепочку закрытия месяца (шаг пула
 * T11 order 500 → шаг лидерского T08 order 800, DEC-053) на одних и тех же строках
 * v2_structure_bonuses и проверяем, что база лидерского = after_cap × factor/10000,
 * а НЕ after_cap. До фикса (T11 не переписывал net_cents) — тест красный (переплата).
 */
class LeadershipCalibrationIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2GlobalBonus;
    use SeedsV2Pool;

    private const MONTH = '2026-07';

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Достигнутый тир участника (v2_tier_history). */
    private function seedTier(int $memberId, string $tier): void
    {
        \Illuminate\Support\Facades\DB::table('v2_tier_history')->insertOrIgnore([
            'member_id' => $memberId,
            'tier' => $tier,
            'tier_before' => null,
            'basis_personal_pv' => '0',
            'source_order_id' => null,
            'policy_version_id' => 1,
            'effective_at' => CarbonImmutable::parse('2026-01-15', 'UTC'),
            'created_at' => now(),
        ]);
    }

    private function closeMonthFully(string $month): void
    {
        $closer = app(PeriodCloseService::class);
        $closer->closeHalfMonth("{$month}-H1");
        $closer->closeHalfMonth("{$month}-H2");
        $closer->closeMonth($month);
    }

    public function test_leadership_base_is_calibrated_structure_not_after_cap(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->activateGlobalBonusPolicy();
        $this->enableFeatureFlags('mh_plan_v2_periods', 'mh_v2_pool', 'mh_v2_leadership');

        // Спонсор Y (Director/ELITE) — получатель лидерского; даунлайн X — источник.
        $sponsor = $this->makeMember();
        $this->seedRank($sponsor, StatusCode::DIRECTOR);
        $this->seedTier($sponsor, 'ELITE');

        $downline = $this->makeMember($sponsor);
        $this->seedRank($downline, StatusCode::CONSULTANT);

        // Структурная премия даунлайна после капов = 1 000 000; база BV = 1 000 000,
        // rate 6000 bps → pool_cap 600 000, factor 6000. Калиброванная выплата = 600 000.
        $afterCap = 1_000_000;
        $this->seedSnapshot($downline, '0', $afterCap, CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $this->seedStructureBonus($downline, self::MONTH, $afterCap);

        // --- Боевой month-close: калибровка (500) ПЕРЕД лидерским (800) ---
        $this->closeMonthFully(self::MONTH);

        // Калибровка закоммичена с factor 6000.
        $cal = PoolCalibration::query()->where('month', self::MONTH)
            ->where('status', PoolCalibration::STATUS_COMMITTED)->sole();
        $this->assertSame(6000, $cal->factor_bps);
        $this->assertSame(6000, app(PoolCalibrationReader::class)->factorBpsFor(self::MONTH));

        // Единая истина: net_cents структурной = after_cap × factor = 600 000 (не 1 000 000).
        $sb = StructureBonus::query()->where('member_id', $downline)->sole();
        $this->assertSame(600_000, $sb->net_cents, 'net_cents должен быть калиброванной выплатой');
        $this->assertSame(1_000_000, $sb->after_cap_cents, 'after_cap не трогаем');

        // Лидерский depth-1 Director = 20% от КАЛИБРОВАННОЙ базы 600 000 = 120 000
        // (а НЕ 20% от after_cap 1 000 000 = 200 000 — это была бы переплата).
        $line = LeadershipBonusLine::query()
            ->where('receiver_member_id', $sponsor)
            ->where('status', LeadershipBonusLine::STATUS_POSTED)
            ->sole();
        $this->assertSame(600_000, (int) $line->base_cents);
        $this->assertSame(120_000, (int) $line->amount_cents);
        $this->assertSame(
            120_000,
            (int) MemberAccountV2::query()->where('member_id', $sponsor)->value('os_available_cents'),
        );
    }
}
