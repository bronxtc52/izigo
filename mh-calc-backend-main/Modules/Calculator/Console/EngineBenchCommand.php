<?php

namespace Modules\Calculator\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calculator\Domain\CompensationEngine;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\Support\Bench\QueryStatsCollector;
use Modules\Calculator\Support\Bench\SyntheticTreeGenerator;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Modules\Calculator\V2\Services\PolicyVersionService;
use RuntimeException;

/**
 * t3: воспроизводимый перф-бенчмарк движка пересчёта (measurement-first, БЕЗ оптимизаций).
 * Чистая ОБВЯЗКА: ядро (Domain/**, V2/**) только вызывается, не меняется ни строкой.
 *
 * Фазы V1: (a) EloquentNetworkRepository::load, (b) чистый CompensationEngine::calculate,
 * (c) полный ActivationService::activate (новый idempotency_key + смена package_id листа
 * → полный recompute + персист снапшота + ledger-дельты).
 * Фазы V2: (d) markPaid-путь заказа (V1 activate + PaidOrderV2Pipeline под флагами),
 * (e) разовый StructureBonusRunCommand текущего half-month окна.
 *
 * Метрики: wall-time hrtime(), SQL count/время через DB::listen-агрегатор
 * (НЕ enableQueryLog), peak memory через memory_reset_peak_usage().
 *
 * ГАРДЫ (амендмент A-t3), проверяются ДО любой деструктивной операции:
 *  - environment('production') → отказ;
 *  - allowlist имён БД (izigo_bench | izigo_test*);
 *  - migrate:fresh — ТОЛЬКО при явном --fresh; без --fresh непустая members → отказ.
 * Команда НЕ регистрируется в prod-контейнере (guard в CalculatorServiceProvider) и
 * НЕ ставится в расписание. В CI живёт только лёгкий smoke (~50 узлов).
 *
 * Флаги V2: при --fresh команда включает их сама (БД одноразовая, bench-owned);
 * без --fresh чужие флаги НЕ трогает — выключены → V2-сценарий честно скипается.
 */
class EngineBenchCommand extends Command
{
    protected $signature = 'calculator:bench-engine
        {--sizes=1000,5000,20000 : размеры дерева через запятую}
        {--iterations=3 : число измеряемых итераций на фазу}
        {--engine=v1,v2 : какие сценарии мерить (v1 | v2 | v1,v2)}
        {--seed=20260720 : fixed seed генератора дерева}
        {--fresh : разрешить migrate:fresh перед каждым размером (ДЕСТРУКТИВНО, только allowlist-БД)}
        {--json= : путь для JSON-результатов}
        {--md= : путь для Markdown-таблиц}';

    protected $description = 'Перф-бенчмарк движка пересчёта (dev/test-only): V1 load/engine/activate + V2 markPaid/structure-bonus';

    /** Флаги, необходимые V2-сценарию (план t3: пайплайн volumes/statuses/referral/awards). */
    private const V2_FLAGS = ['mh_plan_v2_engine', 'mh_v2_volumes', 'mh_v2_statuses', 'mh_v2_referral', 'mh_v2_awards'];

    public function handle(
        EloquentNetworkRepository $networkRepository,
        EloquentPlanRepository $planRepository,
        ActivationService $activation,
        FeatureFlagService $flags,
    ): int {
        // ---- Гарды ДО любой деструктивной операции (A-t3) -------------------
        if (app()->environment('production')) {
            $this->error('bench: запуск в production запрещён.');

            return self::FAILURE;
        }

        $dbName = (string) DB::connection()->getDatabaseName();
        try {
            SyntheticTreeGenerator::assertDatabaseAllowed($dbName);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $fresh = (bool) $this->option('fresh');
        $sizes = collect(explode(',', (string) $this->option('sizes')))
            ->map(fn ($s) => (int) trim($s))->filter(fn ($s) => $s > 0)->values()->all();
        $iterations = max(1, (int) $this->option('iterations'));
        $seed = (int) $this->option('seed');
        $engines = collect(explode(',', (string) $this->option('engine')))
            ->map(fn ($e) => strtolower(trim($e)))->filter()->unique()->values()->all();

        if ($sizes === [] || array_diff($engines, ['v1', 'v2']) !== []) {
            $this->error('bench: проверьте --sizes и --engine (допустимо v1, v2).');

            return self::FAILURE;
        }

        if (! $fresh) {
            if (DB::table('members')->exists()) {
                $this->error('bench: таблица members непуста. Деструктивная очистка выполняется '
                    . 'ТОЛЬКО при явном --fresh (migrate:fresh по allowlist-БД).');

                return self::FAILURE;
            }
            if (count($sizes) > 1) {
                $this->error('bench: несколько размеров подряд требуют --fresh (сброс БД между размерами).');

                return self::FAILURE;
            }
        }

        $collector = new QueryStatsCollector();
        $collector->register();

        $report = [
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'db' => $dbName,
                'driver' => DB::connection()->getDriverName(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'seed' => $seed,
                'iterations' => $iterations,
                'engines' => $engines,
                'fresh' => $fresh,
            ],
            'sizes' => [],
        ];

        foreach ($sizes as $size) {
            $this->info("== size={$size} ==");

            if ($fresh) {
                // Гарды прошли выше; единственная деструктивная операция бенча.
                $this->callSilent('migrate:fresh', ['--force' => true]);
            }

            $generator = new SyntheticTreeGenerator();
            $tree = $generator->generate($size, $seed); // внутри — свои гарды до записи
            $this->line("дерево: {$tree['nodes']} узлов, checksum {$tree['checksum']}");

            $collector->resetAggregate();
            $plan = $planRepository->load();
            $phases = [];

            // Warmup (не измеряется): прогрев автозагрузки/коннекта/кэшей планировщика PG.
            $warm = $networkRepository->load();
            (new CompensationEngine($plan))->calculate($warm);
            unset($warm);

            if (in_array('v1', $engines, true)) {
                $network = null;
                for ($i = 1; $i <= $iterations; $i++) {
                    $phases['v1_load'][] = $this->measure($collector, function () use (&$network, $networkRepository): void {
                        $network = $networkRepository->load();
                    });
                    $phases['v1_engine'][] = $this->measure($collector, function () use ($network, $plan): void {
                        (new CompensationEngine($plan))->calculate($network);
                    });

                    // Полный путь: новый idempotency_key + смена package_id листа → полный пересчёт.
                    $pkg = $tree['package_ids'][$i % count($tree['package_ids'])];
                    $key = sprintf('bench:%d:%d:%s', $size, $i, Str::random(8));
                    $phases['v1_activate'][] = $this->measure(
                        $collector,
                        fn () => $activation->activate($tree['leaf_id'], $pkg, $key),
                    );
                }
                $network = null;
            }

            $v2 = ['status' => 'not_requested', 'reason' => null];
            if (in_array('v2', $engines, true)) {
                [$v2, $ctx] = $this->setupV2($fresh, $flags, $tree);
                if ($v2['status'] === 'ok') {
                    /** @var OrderService $orders */
                    $orders = app(OrderService::class);
                    $leaf = Member::query()->findOrFail($tree['leaf_id']);
                    for ($i = 1; $i <= $iterations; $i++) {
                        $productId = $ctx['products'][$i % count($ctx['products'])];
                        $orderId = (int) $orders->create($leaf, $productId, 1, sprintf('bench-v2:%d:%d:%s', $size, $i, Str::random(8)))['id'];
                        // Как в проде: markPaid внутри транзакции платёжного вебхука.
                        $phases['v2_mark_paid'][] = $this->measure(
                            $collector,
                            fn () => DB::transaction(fn () => $orders->markPaid($orderId)),
                        );

                        $rc = null;
                        $phases['v2_structure_bonus'][] = $this->measure($collector, function () use (&$rc, $ctx): void {
                            $rc = $this->callSilent('v2:structure-bonus:run', ['period' => $ctx['period']]);
                        });
                        if ($rc !== self::SUCCESS) {
                            $this->warn("v2:structure-bonus:run вернул код {$rc} (итерация {$i}).");
                        }
                    }
                } else {
                    $this->warn('V2-сценарий пропущен: ' . $v2['reason']);
                }
            }

            // Контроль методики: чистое ядро на ФИНАЛЬНОМ состоянии сети должно сойтись
            // с персистом полного activate() (сумма member_earnings и ledger-нетто расхода).
            $finalNetwork = $networkRepository->load();
            $finalResult = (new CompensationEngine($plan))->calculate($finalNetwork);
            $engineTotal = 0;
            foreach ($finalResult->lines() as $line) {
                $engineTotal += $line->amount->cents;
            }
            $earningsTotal = (int) MemberEarning::query()
                ->selectRaw('COALESCE(SUM(ROUND(total * 100)), 0) AS cents')->value('cents');
            $ledgerNet = (int) DB::table('ledger_entries')
                ->where('account_type', LedgerService::ACC_COMMISSION_EXPENSE)
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS net")
                ->value('net');

            $sizeReport = [
                'size' => $size,
                'tree_checksum' => $tree['checksum'],
                'phases' => $this->summarizePhases($phases),
                'v2' => $v2,
                'consistency' => [
                    'engine_total_cents' => $engineTotal,
                    'earnings_total_cents' => $earningsTotal,
                    'ledger_expense_net_cents' => $ledgerNet,
                    'earnings_rows' => MemberEarning::query()->count(),
                    'members' => Member::query()->count(),
                ],
                'top_sql' => $collector->top(10),
            ];
            $report['sizes'][] = $sizeReport;

            $this->renderSize($sizeReport);
        }

        $this->writeArtifacts($report);

        return self::SUCCESS;
    }

    /**
     * Замер одной фазы: wall (hrtime), SQL окна (DB::listen), peak memory.
     *
     * @return array{wall_ms: float, sql_count: int, sql_time_ms: float, peak_mb: float}
     */
    private function measure(QueryStatsCollector $collector, callable $fn): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $collector->start();
        $t0 = hrtime(true);
        $fn();
        $wallMs = (hrtime(true) - $t0) / 1e6;
        $window = $collector->stop();

        return [
            'wall_ms' => round($wallMs, 1),
            'sql_count' => $window['sql_count'],
            'sql_time_ms' => $window['sql_time_ms'],
            'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * Подготовка V2-сценария. При --fresh включает флаги/политику сам (bench-owned БД);
     * без --fresh только проверяет готовность и честно скипается, если её нет.
     *
     * @param array{leaf_id: int, package_ids: list<int>} $tree
     * @return array{0: array{status: string, reason: ?string}, 1: ?array{period: string, products: list<int>}}
     */
    private function setupV2(bool $fresh, FeatureFlagService $flags, array $tree): array
    {
        if ($fresh) {
            foreach (self::V2_FLAGS as $flag) {
                $flags->set($flag, true);
            }
        }

        $missing = array_values(array_filter(self::V2_FLAGS, fn (string $f) => ! $flags->isEnabled($f)));
        if ($missing !== []) {
            return [[
                'status' => 'skipped',
                'reason' => 'выключены флаги: ' . implode(', ', $missing)
                    . ' (без --fresh команда чужие флаги не включает)',
            ], null];
        }

        // Активная policy-версия: при --fresh активируем каноническую по умолчанию.
        if ($fresh) {
            $policies = app(PolicyVersionService::class);
            $draft = $policies->createDraft('bench-' . Str::lower(Str::random(6)), DefaultPolicyConfig::doc(), null);
            $policies->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
        }
        try {
            app(PolicyVersionResolver::class)->forDate(CarbonImmutable::now());
        } catch (\Throwable $e) {
            return [['status' => 'skipped', 'reason' => 'нет активной V2-политики: ' . $e->getMessage()], null];
        }

        // Календарь периодов — идемпотентная аддитивная запись (не деструктив).
        app(PeriodService::class)->ensureCalendar(now());
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_HALF_MONTH)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->value('code');
        if ($period === null) {
            return [['status' => 'skipped', 'reason' => 'не найден текущий half-month период'], null];
        }

        // Bench-товар на каждый пакет (idempotent по sku) — для смены package_id листа.
        $products = [];
        foreach ($tree['package_ids'] as $pkgId) {
            $products[] = (int) Product::query()->firstOrCreate(
                ['sku' => 'BENCH-' . $pkgId],
                [
                    'name' => 'Bench Tariff #' . $pkgId,
                    'price_usdt_cents' => 9000,
                    'pv' => 90,
                    'bv_usd_cents' => 9000,
                    'package_id' => $pkgId,
                    'is_active' => true,
                    'sort' => 900 + $pkgId,
                ],
            )->id;
        }

        return [['status' => 'ok', 'reason' => null], ['period' => (string) $period, 'products' => $products]];
    }

    /**
     * mean/min/max по итерациям каждой фазы.
     *
     * @param array<string, list<array<string, int|float>>> $phases
     * @return array<string, array{iterations: list<array<string, int|float>>, mean: array<string, float>, min: array<string, float>, max: array<string, float>}>
     */
    private function summarizePhases(array $phases): array
    {
        $out = [];
        foreach ($phases as $phase => $iterations) {
            $agg = ['mean' => [], 'min' => [], 'max' => []];
            foreach (['wall_ms', 'sql_count', 'sql_time_ms', 'peak_mb'] as $metric) {
                $values = array_map(fn (array $it) => (float) $it[$metric], $iterations);
                $agg['mean'][$metric] = round(array_sum($values) / count($values), 1);
                $agg['min'][$metric] = round(min($values), 1);
                $agg['max'][$metric] = round(max($values), 1);
            }
            $out[$phase] = ['iterations' => $iterations] + $agg;
        }

        return $out;
    }

    /** @param array<string, mixed> $sizeReport */
    private function renderSize(array $sizeReport): void
    {
        $rows = [];
        foreach ($sizeReport['phases'] as $phase => $data) {
            $m = $data['mean'];
            $rows[] = [
                $phase,
                $m['wall_ms'] . ' (' . $data['min']['wall_ms'] . '…' . $data['max']['wall_ms'] . ')',
                $m['sql_count'],
                $m['sql_time_ms'],
                $m['peak_mb'],
            ];
        }
        $this->table(['фаза', 'wall ms (min…max)', 'SQL шт', 'SQL ms', 'peak MB'], $rows);

        $c = $sizeReport['consistency'];
        $this->line(sprintf(
            'консистентность: engine=%d ¢, earnings=%d ¢, ledger_net=%d ¢, earnings_rows=%d/%d members',
            $c['engine_total_cents'], $c['earnings_total_cents'], $c['ledger_expense_net_cents'],
            $c['earnings_rows'], $c['members'],
        ));

        $topRows = array_map(
            fn (array $r) => [Str::limit($r['sql'], 110), $r['count'], $r['time_ms']],
            array_slice($sizeReport['top_sql'], 0, 10),
        );
        $this->table(['SQL (нормализован)', 'кол-во', 'время ms'], $topRows);
    }

    /** @param array<string, mixed> $report */
    private function writeArtifacts(array $report): void
    {
        $jsonPath = (string) $this->option('json');
        if ($jsonPath !== '') {
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $this->info("JSON: {$jsonPath}");
        }

        $mdPath = (string) $this->option('md');
        if ($mdPath !== '') {
            file_put_contents($mdPath, $this->toMarkdown($report));
            $this->info("Markdown: {$mdPath}");
        }
    }

    /** @param array<string, mixed> $report */
    private function toMarkdown(array $report): string
    {
        $meta = $report['meta'];
        $md = "# Бенчмарк движка пересчёта — {$meta['generated_at']}\n\n"
            . "БД: `{$meta['db']}` ({$meta['driver']}), PHP {$meta['php']}, Laravel {$meta['laravel']}, "
            . "seed {$meta['seed']}, итераций {$meta['iterations']}, engines: " . implode(',', $meta['engines']) . "\n";

        foreach ($report['sizes'] as $s) {
            $md .= "\n## size={$s['size']}\n\n";
            $md .= "| фаза | wall ms (mean) | min…max | SQL шт | SQL ms | peak MB |\n|---|---:|---:|---:|---:|---:|\n";
            foreach ($s['phases'] as $phase => $d) {
                $md .= sprintf(
                    "| %s | %s | %s…%s | %s | %s | %s |\n",
                    $phase, $d['mean']['wall_ms'], $d['min']['wall_ms'], $d['max']['wall_ms'],
                    $d['mean']['sql_count'], $d['mean']['sql_time_ms'], $d['mean']['peak_mb'],
                );
            }
            if (($s['v2']['status'] ?? '') === 'skipped') {
                $md .= "\nV2: пропущен — {$s['v2']['reason']}\n";
            }
            $md .= "\nТоп SQL:\n\n| SQL | кол-во | время ms |\n|---|---:|---:|\n";
            foreach ($s['top_sql'] as $r) {
                $md .= '| `' . str_replace('|', '\\|', $r['sql']) . "` | {$r['count']} | {$r['time_ms']} |\n";
            }
        }

        return $md;
    }
}
