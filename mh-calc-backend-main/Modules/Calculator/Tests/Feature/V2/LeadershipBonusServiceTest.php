<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\LeadershipBonusLine;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Bonus\LeadershipBonusService;
use Tests\TestCase;

/**
 * T08 [ДЕНЬГИ, CAL-LED-001]: оркестратор лидерского бонуса на реальных данных —
 * golden S28 Director/ELITE (ладдер 20-1% по 5 источникам глубины 1-5 = 595 000 центов),
 * база DEC-029 (net структурной премии, эмуляция 60%-калибровки T11), rank-gap блок
 * DEC-030, начисление на ОС кредит-лотом 1 год, идемпотентность, guard закрытого периода.
 */
class LeadershipBonusServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';

    private function service(): LeadershipBonusService
    {
        return app(LeadershipBonusService::class);
    }

    private function enableFlag(): void
    {
        app(FeatureFlagService::class)->set('mh_v2_leadership', true);
    }

    /** Достигнутый тир участника (v2_tier_history). */
    private function seedTier(int $memberId, string $tier): void
    {
        DB::table('v2_tier_history')->insertOrIgnore([
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

    /** Строка структурной премии источника (net_cents = база лидерского DEC-029). */
    private function seedStructure(CalcPeriod $period, int $memberId, int $netCents, ?int $afterCap = null): int
    {
        return (int) DB::table('v2_structure_bonuses')->insertGetId([
            'period_id' => $period->id,
            'member_id' => $memberId,
            'policy_version_id' => 1,
            'rank_code' => StatusCode::CONSULTANT->value,
            'rate_bps' => 500,
            'matched_pv' => '0',
            'matched_bv_cents' => $afterCap ?? $netCents,
            'gross_cents' => $afterCap ?? $netCents,
            'half_cap_cents' => 0,
            'monthly_cap_cents' => 0,
            'cap_remaining_before_cents' => 0,
            'after_cap_cents' => $afterCap ?? $netCents,
            'forfeited_cents' => 0,
            'net_cents' => $netCents,
            'accrual_month' => substr($period->code, 0, 7),
            'status' => StructureBonus::STATUS_POSTED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------- golden S28

    public function test_golden_director_elite_ladder(): void
    {
        $this->activateGlobalBonusPolicy(); // активирует полную политику (в т.ч. leadership)
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        // Director ← M1 ← M2 ← M3 ← M4 ← M5 (sponsor-цепочка), каждый Mi — источник премии.
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');

        $bases = [1_000_000, 1_800_000, 2_200_000, 2_500_000, 3_000_000];
        $prev = $d;
        foreach ($bases as $base) {
            $m = $this->makeMember($prev);          // sponsor = предыдущий
            $this->seedRank($m, StatusCode::CONSULTANT); // ниже MANAGER — сам не платит
            $this->seedStructure($period, $m, $base);
            $prev = $m;
        }

        $metrics = $this->service()->runForPeriod($period);

        // Director получает по одной строке с каждого источника на своей глубине.
        $lines = LeadershipBonusLine::query()
            ->where('receiver_member_id', $d)
            ->where('status', LeadershipBonusLine::STATUS_POSTED)
            ->orderBy('depth')
            ->get();
        $this->assertCount(5, $lines);
        $this->assertSame([200_000, 180_000, 110_000, 75_000, 30_000], $lines->pluck('amount_cents')->map('intval')->all());
        $this->assertSame([1, 2, 3, 4, 5], $lines->pluck('depth')->map('intval')->all());

        // Σ = 595 000 центов на ОС (кредит-лот 1 год).
        $this->assertSame(595_000, (int) $lines->sum('amount_cents'));
        $this->assertSame(595_000, (int) MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
        $this->assertSame(595_000, $metrics['posted_cents']);

        $lots = WalletLotV2::query()->where('member_id', $d)->where('source_type', 'leadership')->get();
        $this->assertCount(5, $lots);
        foreach ($lots as $lot) {
            $this->assertSame(WalletLotV2::ACCOUNT_OS, $lot->account);
            $this->assertNotNull($lot->expires_at); // 1 год, не бессрочно
        }
    }

    // ---------------------------------------------------------------- идемпотентность

    public function test_idempotent_rerun_no_double_posting(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');
        $m = $this->makeMember($d);
        $this->seedRank($m, StatusCode::CONSULTANT);
        $this->seedStructure($period, $m, 1_000_000);

        $this->service()->runForPeriod($period);
        $linesAfterFirst = LeadershipBonusLine::query()->count();
        $lotsAfterFirst = WalletLotV2::query()->where('source_type', 'leadership')->count();

        $this->service()->runForPeriod($period); // повтор — no-op

        $this->assertSame($linesAfterFirst, LeadershipBonusLine::query()->count());
        $this->assertSame($lotsAfterFirst, WalletLotV2::query()->where('source_type', 'leadership')->count());
        $this->assertSame(200_000, (int) MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
    }

    // ---------------------------------------------------------------- база DEC-029

    public function test_base_is_net_cents_after_calibration_not_after_cap(): void
    {
        // net_cents = 500 000 (эмуляция 60%-калибровки T11: after_cap 1 000 000 × 0.5).
        // Лидерский считается от NET, не от after_cap.
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');
        $m = $this->makeMember($d);
        $this->seedRank($m, StatusCode::CONSULTANT);
        $this->seedStructure($period, $m, netCents: 500_000, afterCap: 1_000_000);

        $this->service()->runForPeriod($period);

        // 20% от NET 500 000 = 100 000 (а не 200 000 от after_cap).
        $this->assertSame(100_000, (int) MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
    }

    public function test_zero_net_source_produces_no_leadership(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');
        $m = $this->makeMember($d);
        $this->seedRank($m, StatusCode::CONSULTANT);
        $this->seedStructure($period, $m, netCents: 0, afterCap: 1_000_000); // срезано капом/калибровкой в 0

        $metrics = $this->service()->runForPeriod($period);

        $this->assertSame(0, $metrics['sources']); // базовых строк net>0 нет
        $this->assertSame(0, LeadershipBonusLine::query()->count());
        $this->assertNull(MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
    }

    // ---------------------------------------------------------------- rank-gap (DEC-030)

    public function test_rank_gap_block_diamond_source_no_payout(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        // Director получатель; источник Diamond (ordinal 10) → 10 >= 7+3 → блок.
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');
        $diamond = $this->makeMember($d);
        $this->seedRank($diamond, StatusCode::DIAMOND_DIRECTOR);
        $this->seedTier($diamond, 'ELITE');
        $this->seedStructure($period, $diamond, 1_000_000);

        $this->service()->runForPeriod($period);

        $line = LeadershipBonusLine::query()->where('receiver_member_id', $d)->firstOrFail();
        $this->assertSame(LeadershipBonusLine::STATUS_EXCLUDED, $line->status);
        $this->assertSame('RANK_GAP_BLOCK', $line->exclusion_reason);
        $this->assertSame($diamond, (int) $line->blocking_member_id);
        $this->assertNull(MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
    }

    public function test_sapphire_source_pays_director(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);

        // Sapphire (ord 9) → 9 >= 7+3=10? нет → платится (20% depth1).
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedTier($d, 'ELITE');
        $sapphire = $this->makeMember($d);
        $this->seedRank($sapphire, StatusCode::SAPPHIRE_DIRECTOR);
        $this->seedTier($sapphire, 'ELITE');
        $this->seedStructure($period, $sapphire, 1_000_000);

        $this->service()->runForPeriod($period);

        $this->assertSame(200_000, (int) MemberAccountV2::query()->where('member_id', $d)->value('os_available_cents'));
    }

    // ---------------------------------------------------------------- guard

    public function test_closed_period_rejected(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableFlag();
        $period = $this->ensurePeriod(self::MONTH);
        $this->markPeriodClosed($period);

        $this->expectException(\Modules\Calculator\V2\Services\Periods\ClosedPeriodException::class);
        $this->service()->runForPeriod($period->fresh());
    }
}
