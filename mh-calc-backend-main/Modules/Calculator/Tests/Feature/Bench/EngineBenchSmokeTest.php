<?php

namespace Modules\Calculator\Tests\Feature\Bench;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Tests\TestCase;

/**
 * t3: лёгкий CI-smoke бенчмарк-команды (~50 узлов, 1 итерация) — единственное,
 * что из бенча попадает в обычный php artisan test. Тяжёлые размеры (1k/5k/20k)
 * гоняются только вручную (--fresh, БД izigo_bench) и в CI не идут.
 */
class EngineBenchSmokeTest extends TestCase
{
    use RefreshDatabase;

    private string $jsonPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jsonPath = tempnam(sys_get_temp_dir(), 'bench') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->jsonPath);
        parent::tearDown();
    }

    private function runBench(array $overrides = []): array
    {
        $this->artisan('calculator:bench-engine', $overrides + [
            '--sizes' => '50',
            '--iterations' => '1',
            '--engine' => 'v1',
            '--json' => $this->jsonPath,
        ])->assertExitCode(0);

        $report = json_decode((string) file_get_contents($this->jsonPath), true);
        $this->assertIsArray($report, 'JSON-отчёт обязан быть валидным');

        return $report;
    }

    public function testV1BenchProducesMetricsAndKeepsDataInvariants(): void
    {
        $report = $this->runBench();

        $size = $report['sizes'][0];
        $this->assertSame(50, $size['size']);

        // Метрики по каждой фазе V1.
        foreach (['v1_load', 'v1_engine', 'v1_activate'] as $phase) {
            $this->assertArrayHasKey($phase, $size['phases'], "нет фазы {$phase}");
            $iteration = $size['phases'][$phase]['iterations'][0];
            foreach (['wall_ms', 'sql_count', 'sql_time_ms', 'peak_mb'] as $metric) {
                $this->assertArrayHasKey($metric, $iteration);
                $this->assertGreaterThanOrEqual(0, $iteration[$metric]);
            }
            $this->assertArrayHasKey('mean', $size['phases'][$phase]);
        }
        // Чистое ядро — 0 SQL (read-only вызов без БД); полный activate — много SQL.
        $this->assertSame(0, $size['phases']['v1_engine']['iterations'][0]['sql_count']);
        $this->assertGreaterThan(0, $size['phases']['v1_activate']['iterations'][0]['sql_count']);
        $this->assertNotEmpty($size['top_sql']);

        // Смок консистентности методики: чистый CompensationEngine на финальной сети
        // даёт тот же суммарный доход, что персист полного activate().
        $c = $size['consistency'];
        $this->assertSame($c['engine_total_cents'], $c['earnings_total_cents']);
        $this->assertGreaterThan(0, $c['earnings_total_cents']);

        // Инварианты данных целы: earnings сходится с ledger-дельтами, строк ≤ участников.
        $ledgerNet = (int) DB::table('ledger_entries')
            ->where('account_type', LedgerService::ACC_COMMISSION_EXPENSE)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS net")
            ->value('net');
        $earnings = (int) MemberEarning::query()
            ->selectRaw('COALESCE(SUM(ROUND(total * 100)), 0) AS cents')->value('cents');
        $this->assertSame($earnings, $ledgerNet);
        $this->assertLessThanOrEqual(Member::query()->count(), MemberEarning::query()->count());
    }

    public function testV2ScenarioHonestlySkippedWhenFlagsDisabled(): void
    {
        // Без --fresh команда чужие флаги не включает: V2 скипается с пометкой, код 0.
        $report = $this->runBench(['--engine' => 'v1,v2']);

        $size = $report['sizes'][0];
        $this->assertSame('skipped', $size['v2']['status']);
        $this->assertStringContainsString('mh_plan_v2_engine', $size['v2']['reason']);
        $this->assertArrayNotHasKey('v2_mark_paid', $size['phases']);
        $this->assertArrayNotHasKey('v2_structure_bonus', $size['phases']);
    }

    public function testV2PipelineRunsOnBenchTreeWhenEnabled(): void
    {
        // Готовность V2 обеспечивает тест (как в проде это делает cutover, не бенч):
        // флаги + активная политика + календарь периодов.
        $flags = app(FeatureFlagService::class);
        foreach (['mh_plan_v2_engine', 'mh_v2_volumes', 'mh_v2_statuses', 'mh_v2_referral', 'mh_v2_awards'] as $flag) {
            $flags->set($flag, true);
        }
        $policies = app(PolicyVersionService::class);
        $draft = $policies->createDraft('bench-smoke', DefaultPolicyConfig::doc(), null);
        $policies->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
        app(PeriodService::class)->ensureCalendar(now());

        $report = $this->runBench(['--sizes' => '30', '--engine' => 'v2']);

        $size = $report['sizes'][0];
        $this->assertSame('ok', $size['v2']['status']);
        foreach (['v2_mark_paid', 'v2_structure_bonus'] as $phase) {
            $this->assertArrayHasKey($phase, $size['phases'], "нет фазы {$phase}");
            $this->assertGreaterThan(0, $size['phases'][$phase]['iterations'][0]['sql_count']);
        }
        // markPaid-путь прошёл: V1-персист начислений на месте (пайплайн не сломал семантику).
        $this->assertGreaterThan(0, MemberEarning::query()->count());
    }

    public function testRefusesToRunInProductionEnvironment(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->artisan('calculator:bench-engine', ['--sizes' => '10', '--engine' => 'v1'])
                ->assertExitCode(1);
        } finally {
            $this->app['env'] = $originalEnv;
        }

        $this->assertSame(0, Member::query()->count(), 'в production не должно быть записано ни строки');
    }

    public function testRefusesWhenMembersNotEmptyWithoutFresh(): void
    {
        DB::table('members')->insert([
            'telegram_id' => 1,
            'ref_code' => 'EXISTING00000001',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('calculator:bench-engine', ['--sizes' => '10', '--engine' => 'v1'])
            ->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('members')->count(), 'ни одной новой строки без --fresh');
    }

    public function testRefusesMultipleSizesWithoutFresh(): void
    {
        $this->artisan('calculator:bench-engine', ['--sizes' => '10,20', '--engine' => 'v1'])
            ->assertExitCode(1);

        $this->assertSame(0, (int) DB::table('members')->count());
    }
}
