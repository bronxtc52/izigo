<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPool;
use Modules\Calculator\V2\Models\GlobalBonusQualification;
use Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService;
use Tests\TestCase;

/**
 * T09 [ДЕНЬГИ, обяз.]: месячный расчёт глобального бонуса — golden-суммы пулов
 * (DEC-031/038), пороги долей, наследование пулов, знаменатель=Σ долей (DEC-033),
 * largest-remainder ДО капа (DEC-035), кап 25% (DEC-034), пустой пул, идемпотентность.
 * Вся математика целочисленная (центы/микро-PV).
 */
class GlobalBonusMonthlyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';

    private function paidAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC');
    }

    private function service(): GlobalBonusMonthlyService
    {
        return app(GlobalBonusMonthlyService::class);
    }

    /** allocated + unallocated == pool_amount для КАЖДОГО пула (инвариант двойной записи). */
    private function assertPoolsBalanced(GlobalBonusMonth $month): void
    {
        foreach ($month->pools()->get() as $pool) {
            $this->assertSame(
                (int) $pool->pool_amount_cents,
                (int) $pool->allocated_cents + (int) $pool->unallocated_cents,
                "Пул {$pool->pool_rank}: allocated+unallocated != pool_amount",
            );
            // Σ capped всех аллокаций пула == pool_amount.
            $sumCapped = (int) $pool->allocations()->sum('capped_cents');
            $this->assertSame((int) $pool->pool_amount_cents, $sumCapped, "Пул {$pool->pool_rank}: Σcapped != pool_amount");
        }
    }

    private function poolByRank(GlobalBonusMonth $month, string $rank): GlobalBonusPool
    {
        return $month->pools()->where('pool_rank', $rank)->firstOrFail();
    }

    // --- Golden: суммы пулов из месячного global BV (DEC-031/038) ---

    public function testGoldenPoolAmountsFromGlobalBv(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        // global BV 5 000 000 USD = 500 000 000 центов (обычная покупка без ранга).
        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 500_000_000, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));

        $this->assertSame(500_000_000, (int) $month->global_bv_cents);
        // 1.00 / 0.75 / 0.50 / 0.50 / 0.25 % → 50000/37500/25000/25000/12500 USD.
        $this->assertSame(5_000_000, (int) $this->poolByRank($month, GlobalBonusPool::RANK_DIRECTOR)->pool_amount_cents);
        $this->assertSame(3_750_000, (int) $this->poolByRank($month, GlobalBonusPool::RANK_PEARL)->pool_amount_cents);
        $this->assertSame(2_500_000, (int) $this->poolByRank($month, GlobalBonusPool::RANK_SAPPHIRE)->pool_amount_cents);
        $this->assertSame(2_500_000, (int) $this->poolByRank($month, GlobalBonusPool::RANK_DIAMOND)->pool_amount_cents);
        $this->assertSame(1_250_000, (int) $this->poolByRank($month, GlobalBonusPool::RANK_VP)->pool_amount_cents);
        // Нет квалифицированных — весь каждый пул unallocated (empty_pool).
        $this->assertPoolsBalanced($month);
        $this->assertSame(GlobalBonusPool::REASON_EMPTY_POOL, $this->poolByRank($month, GlobalBonusPool::RANK_DIRECTOR)->unallocated_reason);
    }

    // --- Пороги долей: Director base 100k, max 2 (DEC-032) ---

    /** @dataProvider directorShareCases */
    public function testDirectorSharesThresholds(string $pv, int $expectedShares): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        $m = $this->makeMember();
        $this->seedRank($m, StatusCode::DIRECTOR);
        $this->seedSnapshot($m, $pv, 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $qual = GlobalBonusQualification::query()
            ->where('global_bonus_month_id', $month->id)->where('member_id', $m)->firstOrFail();

        $this->assertSame($expectedShares, (int) $qual->shares);
    }

    public static function directorShareCases(): array
    {
        return [
            '99999 → 0' => ['99999.000000', 0],
            '100000 → 1' => ['100000.000000', 1],
            '199999.99 → 1' => ['199999.990000', 1],
            '200000 → 2' => ['200000.000000', 2],
            '250000 → 2 (max)' => ['250000.000000', 2],
        ];
    }

    /** Конфиг max_shares=3 → floor работает выше 2; базы по рангам (Pearl 400k…VP 6M). */
    public function testMaxSharesThreeAndPerRankBases(): void
    {
        $this->activateGlobalBonusPolicy(['max_shares' => 3]);
        $this->ensurePeriod(self::MONTH);

        // Director base 100k, PV 300k → 3 доли.
        $dir = $this->makeMember();
        $this->seedRank($dir, StatusCode::DIRECTOR);
        $this->seedSnapshot($dir, '300000.000000', 0, $this->paidAt());

        // Pearl base 400k, PV 800k → 2 доли.
        $pearl = $this->makeMember();
        $this->seedRank($pearl, StatusCode::PEARL_DIRECTOR);
        $this->seedSnapshot($pearl, '800000.000000', 0, $this->paidAt());

        // Diamond base 3M, PV 3M → 1 доля.
        $diamond = $this->makeMember();
        $this->seedRank($diamond, StatusCode::DIAMOND_DIRECTOR);
        $this->seedSnapshot($diamond, '3000000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $shares = fn (int $id) => (int) GlobalBonusQualification::query()
            ->where('global_bonus_month_id', $month->id)->where('member_id', $id)->value('shares');

        $this->assertSame(3, $shares($dir));
        $this->assertSame(2, $shares($pearl));
        $this->assertSame(1, $shares($diamond));
    }

    // --- Наследование нижних пулов ---

    public function testInheritanceIntoLowerPools(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        // Sapphire base 1M, PV 2M → 2 доли: участвует в sapphire+pearl+director, НЕ в diamond/vp.
        $sapphire = $this->makeMember();
        $this->seedRank($sapphire, StatusCode::SAPPHIRE_DIRECTOR);
        $this->seedSnapshot($sapphire, '2000000.000000', 0, $this->paidAt());

        // Director PV 100k → 1 доля, только director.
        $director = $this->makeMember();
        $this->seedRank($director, StatusCode::DIRECTOR);
        $this->seedSnapshot($director, '100000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));

        $this->assertSame(3, (int) $this->poolByRank($month, GlobalBonusPool::RANK_DIRECTOR)->total_shares); // 2+1
        $this->assertSame(2, (int) $this->poolByRank($month, GlobalBonusPool::RANK_PEARL)->total_shares);
        $this->assertSame(2, (int) $this->poolByRank($month, GlobalBonusPool::RANK_SAPPHIRE)->total_shares);
        $this->assertSame(0, (int) $this->poolByRank($month, GlobalBonusPool::RANK_DIAMOND)->total_shares);
        $this->assertSame(0, (int) $this->poolByRank($month, GlobalBonusPool::RANK_VP)->total_shares);
    }

    // --- Знаменатель = Σ долей (DEC-033), без капа ---

    public function testDenominatorIsSumOfShares(): void
    {
        // Убираем кап (100%), чтобы проверить чистый unit = pool / Σshares.
        $this->activateGlobalBonusPolicy(['member_cap_bp' => 10000]);
        $this->ensurePeriod(self::MONTH);

        // global BV 30 000 центов → director pool (1%) = 300 центов.
        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 30_000, $this->paidAt());

        // A: 2 доли (PV 200k), B: 1 доля (PV 100k). denominator=3, unit=100.
        $a = $this->makeMember();
        $this->seedRank($a, StatusCode::DIRECTOR);
        $this->seedSnapshot($a, '200000.000000', 0, $this->paidAt());
        $b = $this->makeMember();
        $this->seedRank($b, StatusCode::DIRECTOR);
        $this->seedSnapshot($b, '100000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $pool = $this->poolByRank($month, GlobalBonusPool::RANK_DIRECTOR);

        $this->assertSame(300, (int) $pool->pool_amount_cents);
        $this->assertSame(3, (int) $pool->total_shares);
        $rawA = (int) $pool->allocations()->where('member_id', $a)->value('raw_cents');
        $rawB = (int) $pool->allocations()->where('member_id', $b)->value('raw_cents');
        $this->assertSame(200, $rawA); // 2 доли × unit 100
        $this->assertSame(100, $rawB); // 1 доля × unit 100
        $this->assertSame(0, (int) $pool->unallocated_cents);
        $this->assertPoolsBalanced($month);
    }

    // --- Largest remainder ДО капа: инвариант Σ==pool на случайных долях (DEC-035) ---

    public function testLargestRemainderInvariantRandomShares(): void
    {
        mt_srand(20260712);
        // Кап 100% — изолируем округление от капа. Политика активируется однажды.
        $this->activateGlobalBonusPolicy(['member_cap_bp' => 10000]);
        for ($iter = 0; $iter < 8; $iter++) {
            $this->refreshDatabaseForIteration();
            $this->ensurePeriod(self::MONTH);

            $bv = mt_rand(1, 999) * 101; // произвольный, часто не делится нацело
            $buyer = $this->makeMember();
            $this->seedSnapshot($buyer, '0.000000', $bv, $this->paidAt());

            $n = mt_rand(2, 6);
            for ($i = 0; $i < $n; $i++) {
                $d = $this->makeMember();
                $this->seedRank($d, StatusCode::DIRECTOR);
                // PV 100k или 200k → 1 или 2 доли.
                $pv = mt_rand(0, 1) === 1 ? '200000.000000' : '100000.000000';
                $this->seedSnapshot($d, $pv, 0, $this->paidAt());
            }

            $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
            $this->assertPoolsBalanced($month);
        }
    }

    private function refreshDatabaseForIteration(): void
    {
        // Между итерациями чистим доменные строки (в рамках одной RefreshDatabase-транзакции).
        \Modules\Calculator\V2\Models\GlobalBonusAllocation::query()->delete();
        \Modules\Calculator\V2\Models\GlobalBonusQualification::query()->delete();
        \Modules\Calculator\V2\Models\GlobalBonusPool::query()->delete();
        GlobalBonusMonth::query()->delete();
        \Illuminate\Support\Facades\DB::table('v2_order_volume_snapshots')->delete();
        \Illuminate\Support\Facades\DB::table('v2_rank_history')->delete();
        // gbPlacementLast НЕ сбрасываем: members сохраняются, placement-чейн продолжается
        // (single-root допускает ровно один корень на всю транзакцию теста).
    }

    // --- Кап 25% (DEC-034): единственный участник получает max 25%, остаток компании ---

    public function testCap25PercentSingleMember(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        // director pool = 400 центов (global BV 40 000).
        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 40_000, $this->paidAt());

        // Единственный Director, 2 доли (PV 200k) → raw = весь пул 400, capped = 25% = 100.
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedSnapshot($d, '200000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $pool = $this->poolByRank($month, GlobalBonusPool::RANK_DIRECTOR);

        $alloc = $pool->allocations()->where('member_id', $d)->firstOrFail();
        $this->assertSame(400, (int) $alloc->raw_cents);
        $this->assertSame(100, (int) $alloc->capped_cents); // 25% пула
        $this->assertSame(100, (int) $alloc->final_cents);
        $this->assertSame(300, (int) $pool->unallocated_cents);
        $this->assertSame(GlobalBonusPool::REASON_CAP_REMAINDER, $pool->unallocated_reason);
        // Остаток — строка UNALLOCATED (member_id NULL), НЕ перераспределён.
        $unalloc = $pool->allocations()->whereNull('member_id')->firstOrFail();
        $this->assertSame(300, (int) $unalloc->capped_cents);
        $this->assertSame(GlobalBonusAllocation::KIND_UNALLOCATED, $unalloc->kind);
        $this->assertPoolsBalanced($month);
    }

    // --- Пустой пул: нет квалифицированных VP → весь VP-пул компании ---

    public function testEmptyVpPoolUnallocated(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 1_000_000, $this->paidAt());
        // Только Director — VP-пул пуст.
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedSnapshot($d, '100000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $vp = $this->poolByRank($month, GlobalBonusPool::RANK_VP);

        $this->assertSame(0, (int) $vp->total_shares);
        $this->assertSame((int) $vp->pool_amount_cents, (int) $vp->unallocated_cents);
        $this->assertSame(GlobalBonusPool::REASON_EMPTY_POOL, $vp->unallocated_reason);
        $this->assertPoolsBalanced($month);
    }

    // --- Negative: ранг < Director / достигнут после месяца / давно, но PV=0 ---

    public function testRankBelowDirectorDoesNotQualify(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        $m = $this->makeMember();
        $this->seedRank($m, StatusCode::PLATINUM_MANAGER); // ordinal 6 < Director
        $this->seedSnapshot($m, '10000000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $this->assertSame(0, GlobalBonusQualification::query()->where('global_bonus_month_id', $month->id)->count());
    }

    public function testRankAchievedAfterMonthDoesNotQualify(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        $m = $this->makeMember();
        $this->seedRank($m, StatusCode::DIRECTOR, CarbonImmutable::parse('2026-04-05', 'UTC')); // после марта
        $this->seedSnapshot($m, '100000.000000', 0, $this->paidAt());

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $this->assertSame(0, GlobalBonusQualification::query()->where('global_bonus_month_id', $month->id)->count());
    }

    public function testRankLongAgoButZeroMonthPvHasZeroShares(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);

        $m = $this->makeMember();
        $this->seedRank($m, StatusCode::DIRECTOR); // 2026-02-01
        // Никаких снапшотов месяца → tree PV = 0.

        $month = $this->service()->allocateForMonth($this->ensurePeriod(self::MONTH));
        $qual = GlobalBonusQualification::query()
            ->where('global_bonus_month_id', $month->id)->where('member_id', $m)->firstOrFail();
        $this->assertSame(0, (int) $qual->shares);
        // Аллокаций участнику нет.
        $this->assertSame(0, GlobalBonusAllocation::query()
            ->where('global_bonus_month_id', $month->id)->where('member_id', $m)->count());
    }

    // --- Идемпотентность: draft → детерминированный пересчёт; final → no-op ---

    public function testDraftRecomputeIsDeterministicAndFinalIsNoop(): void
    {
        $this->activateGlobalBonusPolicy();
        $period = $this->ensurePeriod(self::MONTH);

        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 300_007, $this->paidAt());
        foreach (['100000.000000', '200000.000000', '150000.000000'] as $pv) {
            $d = $this->makeMember();
            $this->seedRank($d, StatusCode::DIRECTOR);
            $this->seedSnapshot($d, $pv, 0, $this->paidAt());
        }

        $first = $this->service()->allocateForMonth($period);
        $snapshot1 = $this->allocationFingerprint($first);

        // Повторный прогон draft → байт-в-байт те же суммы.
        $second = $this->service()->allocateForMonth($period);
        $this->assertSame($snapshot1, $this->allocationFingerprint($second));

        // Финализируем → повторный allocate = no-op (те же строки).
        $this->service()->finalizeMonth($period);
        $this->assertSame(GlobalBonusMonth::STATUS_FINAL, $this->service()->allocateForMonth($period)->status);
        $this->assertSame($snapshot1, $this->allocationFingerprint(GlobalBonusMonth::query()->where('month_period_id', $period->id)->firstOrFail()));
    }

    private function allocationFingerprint(GlobalBonusMonth $month): string
    {
        $rows = GlobalBonusAllocation::query()
            ->where('v2_global_bonus_allocations.global_bonus_month_id', $month->id)
            ->join('v2_global_bonus_pools', 'v2_global_bonus_pools.id', '=', 'v2_global_bonus_allocations.pool_id')
            ->orderBy('v2_global_bonus_pools.pool_rank')
            ->orderByRaw('v2_global_bonus_allocations.member_id NULLS LAST')
            ->get([
                'v2_global_bonus_pools.pool_rank',
                'v2_global_bonus_allocations.member_id',
                'v2_global_bonus_allocations.raw_cents',
                'v2_global_bonus_allocations.capped_cents',
                'v2_global_bonus_allocations.final_cents',
            ])
            ->map(fn ($r) => "{$r->pool_rank}:{$r->member_id}:{$r->raw_cents}:{$r->capped_cents}:{$r->final_cents}")
            ->implode('|');

        return md5($rows);
    }
}
