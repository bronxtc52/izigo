<?php

namespace Modules\Calculator\Tests\Unit\V2\Bonus;

use Modules\Calculator\V2\Domain\Bonus\LeadershipCalculator;
use Modules\Calculator\V2\Domain\Bonus\LeadershipChainNode;
use Modules\Calculator\V2\Domain\Bonus\LeadershipLine;
use Modules\Calculator\V2\Domain\Policy\LeadershipRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use PHPUnit\Framework\TestCase;

/**
 * T08 [ДЕНЬГИ] — чистый калькулятор лидерского бонуса CAL-LED-001 без БД:
 * ставки START/BUSINESS/ELITE, глубина по рангу, rank-gap блок (DEC-030 «без передачи»),
 * пропуск ниже MANAGER без компрессии, база DEC-029 пропорциональна, целочисленная
 * математика. Ординалы статусов: MANAGER 2 … DIRECTOR 7 … SAPPHIRE 9, DIAMOND 10, VP 11.
 */
class LeadershipCalculatorTest extends TestCase
{
    private LeadershipCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new LeadershipCalculator();
    }

    private function rule(int $gap = 3): LeadershipRule
    {
        return new LeadershipRule(
            eligibilityStatusMin: StatusCode::MANAGER,
            base: 'PAID_AFTER_CAPS_AND_POOL',
            rankGapBlockOrdinalDiff: $gap,
            startRatesBp: [1000],
            businessRatesBp: [1500],
            eliteRatesBp: [2000, 1000, 500, 300, 100, 100, 100],
            eliteMaxDepth: 7,
        );
    }

    /** Разрешённая ELITE-глубина по рангу (StatusRule::eliteLeadershipDepth). */
    private function eliteDepthFor(StatusCode $rank): int
    {
        return match ($rank) {
            StatusCode::MANAGER, StatusCode::BRONZE_MANAGER => 1,
            StatusCode::SILVER_MANAGER => 2,
            StatusCode::GOLD_MANAGER => 3,
            StatusCode::PLATINUM_MANAGER => 4,
            StatusCode::DIRECTOR => 5,
            StatusCode::PEARL_DIRECTOR => 6,
            StatusCode::SAPPHIRE_DIRECTOR, StatusCode::DIAMOND_DIRECTOR, StatusCode::VICE_PRESIDENT => 7,
            default => 0,
        };
    }

    private function node(int $id, ?StatusCode $rank, ?string $tier): LeadershipChainNode
    {
        return new LeadershipChainNode(
            $id,
            $rank?->value,
            $rank?->ordinal(),
            $tier,
            $rank ? $this->eliteDepthFor($rank) : 0,
        );
    }

    // ---------------------------------------------------------------- START/BUSINESS

    public function test_start_receiver_pays_only_l1_10pct(): void
    {
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [
            $this->node(2, StatusCode::MANAGER, 'START'),   // depth 1 — платится 10%
            $this->node(3, StatusCode::MANAGER, 'START'),   // depth 2 — START не платит глубже
        ];

        $lines = $this->calc->compute($source, 100000, $chain, $this->rule());

        $this->assertTrue($lines[0]->isAccrued());
        $this->assertSame(1000, $lines[0]->rateBp);
        $this->assertSame(10000, $lines[0]->amountCents); // 100000 * 10%
        $this->assertFalse($lines[1]->isAccrued());
        $this->assertSame(LeadershipLine::REASON_DEPTH_NOT_ALLOWED, $lines[1]->exclusionReason);
    }

    public function test_business_receiver_pays_only_l1_15pct(): void
    {
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [$this->node(2, StatusCode::MANAGER, 'BUSINESS')];

        $lines = $this->calc->compute($source, 100000, $chain, $this->rule());

        $this->assertSame(1500, $lines[0]->rateBp);
        $this->assertSame(15000, $lines[0]->amountCents); // 100000 * 15%
    }

    // ---------------------------------------------------------------- ELITE ladder

    public function test_elite_rates_by_depth_full_ladder(): void
    {
        // Все получатели — VP (elite depth 7), 8-й узел не обходится (maxWalk 7).
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [];
        for ($i = 2; $i <= 9; $i++) {
            $chain[] = $this->node($i, StatusCode::VICE_PRESIDENT, 'ELITE');
        }

        $lines = $this->calc->compute($source, 1000000, $chain, $this->rule());

        $expected = [200000, 100000, 50000, 30000, 10000, 10000, 10000]; // 20/10/5/3/1/1/1%
        $this->assertCount(7, $lines); // строго 7 уровней
        foreach ($expected as $i => $amount) {
            $this->assertTrue($lines[$i]->isAccrued(), "depth ".($i + 1)." accrued");
            $this->assertSame($i + 1, $lines[$i]->depth);
            $this->assertSame($amount, $lines[$i]->amountCents);
        }
    }

    public function test_elite_depth_limited_by_rank_and_below_manager_increments_depth(): void
    {
        // source MANAGER; depth1 — CONSULTANT (ниже MANAGER, пропуск БЕЗ компрессии);
        // depth2 — MANAGER ELITE (elite depth 1) → за пределом ранга на depth2 → DEPTH_NOT_ALLOWED.
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [
            $this->node(2, StatusCode::CONSULTANT, 'BUSINESS'),
            $this->node(3, StatusCode::MANAGER, 'ELITE'),
        ];

        $lines = $this->calc->compute($source, 1000000, $chain, $this->rule());

        $this->assertSame(LeadershipLine::REASON_BELOW_MANAGER, $lines[0]->exclusionReason);
        $this->assertSame(1, $lines[0]->depth);
        $this->assertSame(2, $lines[1]->depth); // depth НЕ сжат
        $this->assertFalse($lines[1]->isAccrued());
        $this->assertSame(LeadershipLine::REASON_DEPTH_NOT_ALLOWED, $lines[1]->exclusionReason);
    }

    public function test_below_manager_receiver_never_pays(): void
    {
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [$this->node(2, StatusCode::CONSULTANT, 'ELITE')];

        $lines = $this->calc->compute($source, 100000, $chain, $this->rule());

        $this->assertFalse($lines[0]->isAccrued());
        $this->assertSame(LeadershipLine::REASON_BELOW_MANAGER, $lines[0]->exclusionReason);
    }

    // ---------------------------------------------------------------- rank-gap (DEC-030)

    public function test_director_receives_from_sapphire_source(): void
    {
        // Director (ord7) + источник Sapphire (ord9): 9 >= 7+3=10? нет → платится.
        $source = $this->node(1, StatusCode::SAPPHIRE_DIRECTOR, 'ELITE');
        $chain = [$this->node(2, StatusCode::DIRECTOR, 'ELITE')];

        $lines = $this->calc->compute($source, 1000000, $chain, $this->rule());

        $this->assertTrue($lines[0]->isAccrued());
        $this->assertSame(200000, $lines[0]->amountCents); // 20%
    }

    public function test_director_blocked_by_diamond_source(): void
    {
        // Director (ord7) + источник Diamond (ord10): 10 >= 10 → RANK_GAP_BLOCK.
        $source = $this->node(1, StatusCode::DIAMOND_DIRECTOR, 'ELITE');
        $chain = [$this->node(2, StatusCode::DIRECTOR, 'ELITE')];

        $lines = $this->calc->compute($source, 1000000, $chain, $this->rule());

        $this->assertFalse($lines[0]->isAccrued());
        $this->assertSame(LeadershipLine::REASON_RANK_GAP_BLOCK, $lines[0]->exclusionReason);
        $this->assertSame(1, $lines[0]->blockingMemberId); // виновник = источник
        $this->assertSame(0, $lines[0]->amountCents);
    }

    public function test_blocking_node_mid_path_blocks_receiver(): void
    {
        // source MANAGER (ord2); depth1 Diamond (ord10, платится); depth2 Director (ord7):
        // нижний Diamond 10 >= 7+3=10 → RANK_GAP_BLOCK на Director (subtree-блок середины пути).
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [
            $this->node(2, StatusCode::DIAMOND_DIRECTOR, 'ELITE'),
            $this->node(3, StatusCode::DIRECTOR, 'ELITE'),
        ];

        $lines = $this->calc->compute($source, 1000000, $chain, $this->rule());

        $this->assertTrue($lines[0]->isAccrued()); // Diamond depth1 платится
        $this->assertFalse($lines[1]->isAccrued());
        $this->assertSame(LeadershipLine::REASON_RANK_GAP_BLOCK, $lines[1]->exclusionReason);
        $this->assertSame(2, $lines[1]->blockingMemberId); // виновник — Diamond середины
    }

    public function test_gap_threshold_is_configurable(): void
    {
        // Director + Sapphire source. gap=2: 9 >= 7+2=9 → блок; gap=3 → платится.
        $source = $this->node(1, StatusCode::SAPPHIRE_DIRECTOR, 'ELITE');
        $chain = [$this->node(2, StatusCode::DIRECTOR, 'ELITE')];

        $blocked = $this->calc->compute($source, 1000000, $chain, $this->rule(gap: 2));
        $this->assertSame(LeadershipLine::REASON_RANK_GAP_BLOCK, $blocked[0]->exclusionReason);

        $paid = $this->calc->compute($source, 1000000, $chain, $this->rule(gap: 3));
        $this->assertTrue($paid[0]->isAccrued());
    }

    // ---------------------------------------------------------------- база DEC-029

    public function test_base_dec029_is_proportional(): void
    {
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [$this->node(2, StatusCode::VICE_PRESIDENT, 'ELITE')];

        $full = $this->calc->compute($source, 100000, $chain, $this->rule());
        $half = $this->calc->compute($source, 50000, $chain, $this->rule());

        $this->assertSame(20000, $full[0]->amountCents); // 100000 * 20%
        $this->assertSame(10000, $half[0]->amountCents); // 50000 * 20% — линейно от net
    }

    public function test_integer_floor_rounding(): void
    {
        // base 999 центов * 20% = 199.8 → floor 199 (целочисленно, DEC-002).
        $source = $this->node(1, StatusCode::MANAGER, 'ELITE');
        $chain = [$this->node(2, StatusCode::VICE_PRESIDENT, 'ELITE')]; // depth1 rate 2000

        $lines = $this->calc->compute($source, 999, $chain, $this->rule());

        $this->assertSame(2000, $lines[0]->rateBp);
        $this->assertSame(199, $lines[0]->amountCents); // intdiv(999*2000, 10000) = 199
    }

    // ---------------------------------------------------------------- golden S28 (per-source)

    public function test_golden_director_ladder_l1_l2_per_source(): void
    {
        // Спека S28: Director получает 20% с источника depth1 и 10% с источника depth2.
        // Здесь — по одному вызову на источник (сервис агрегирует по получателю).
        $s1 = $this->node(10, StatusCode::MANAGER, 'ELITE');
        $l1 = $this->calc->compute($s1, 1000000, [$this->node(99, StatusCode::DIRECTOR, 'ELITE')], $this->rule());
        $this->assertSame(200000, $l1[0]->amountCents); // 1 000 000 * 20%

        // Director на depth2 (один пропущенный CONSULTANT-узел ниже, без компрессии).
        $s2 = $this->node(11, StatusCode::MANAGER, 'ELITE');
        $chain2 = [
            $this->node(98, StatusCode::CONSULTANT, 'BUSINESS'),
            $this->node(99, StatusCode::DIRECTOR, 'ELITE'),
        ];
        $l2 = $this->calc->compute($s2, 1800000, $chain2, $this->rule());
        $this->assertSame(2, $l2[1]->depth);
        $this->assertSame(180000, $l2[1]->amountCents); // 1 800 000 * 10%
    }
}
