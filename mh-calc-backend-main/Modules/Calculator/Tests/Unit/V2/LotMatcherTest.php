<?php

namespace Modules\Calculator\Tests\Unit\V2;

use Modules\Calculator\V2\Domain\Volume\LotConsumption;
use Modules\Calculator\V2\Domain\Volume\LotMatcher;
use Modules\Calculator\V2\Domain\Volume\LotSlice;
use Modules\Calculator\V2\Domain\Volume\MatchResult;
use PHPUnit\Framework\TestCase;

/**
 * T03 [ДЕНЬГИ]: чистое ядро матчинга бинара (зеркало CAL-BIN-001, без БД).
 * min(L,R); FIFO earliest-first; частичное потребление; BV пропорционально
 * потреблённому PV (DEC-016) с largest-remainder — ни одного потерянного цента;
 * decimal PV; нулевые матчи с zero_explanation. Голден-кейсы спеки в USD по 468:
 * AT-BIN-001 (100 vs 100, BV 42120 KZT = 9000 центов) и AT-BIN-002 (100 vs 50).
 */
class LotMatcherTest extends TestCase
{
    private LotMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new LotMatcher();
    }

    /** @param LotConsumption[] $consumptions */
    private static function sideSum(array $consumptions, string $side): int
    {
        return array_sum(array_map(
            fn (LotConsumption $c) => $c->side === $side ? $c->bvCentsConsumed : 0,
            $consumptions,
        ));
    }

    public function testGoldenAtBin001EqualSides(): void
    {
        // AT-BIN-001: 100PV vs 100PV, каждый лот BV 42120 KZT -> 9000 центов (468).
        $result = $this->matcher->match(
            [new LotSlice(1, '100.000000', 9000)],
            [new LotSlice(2, '100.000000', 9000)],
        );

        $this->assertSame('100.000000', $result->matchedPv);
        $this->assertSame(9000, $result->matchedBvCents);
        $this->assertSame(9000, $result->leftBvCentsConsumed);
        $this->assertSame(9000, $result->rightBvCentsConsumed);
        $this->assertCount(2, $result->consumptions);
        $this->assertNull($result->zeroReason);
        foreach ($result->consumptions as $c) {
            $this->assertTrue($c->exhausted); // carry 0 — обе стороны выпиты
            $this->assertSame('100.000000', $c->pvConsumed);
        }
    }

    public function testGoldenAtBin002PartialMatchWithCarryover(): void
    {
        // AT-BIN-002: 100 vs 50 => matched 50, carry 50 слева; BV следует за потреблённым.
        $result = $this->matcher->match(
            [new LotSlice(1, '100.000000', 9000)],
            [new LotSlice(2, '50.000000', 4500)],
        );

        $this->assertSame('50.000000', $result->matchedPv);
        $this->assertSame(4500, $result->matchedBvCents);
        $this->assertSame(4500, $result->leftBvCentsConsumed); // 9000 × 50/100
        $this->assertSame(4500, $result->rightBvCentsConsumed);

        $left = $result->consumptions[0];
        $this->assertSame('50.000000', $left->pvConsumed);
        $this->assertFalse($left->exhausted); // carry 50 PV остаётся свободным
        $right = $result->consumptions[1];
        $this->assertTrue($right->exhausted);
    }

    public function testFifoEarliestFirstAcrossMultipleLots(): void
    {
        // Слева три лота FIFO; matched 120 должен выпить первый (50), второй (50)
        // и частично третий (20) — именно в порядке входа.
        $result = $this->matcher->match(
            [
                new LotSlice(1, '50.000000', 4500),
                new LotSlice(2, '50.000000', 4500),
                new LotSlice(3, '100.000000', 9000),
            ],
            [new LotSlice(4, '120.000000', 10800)],
        );

        $this->assertSame('120.000000', $result->matchedPv);
        $leftCons = array_values(array_filter($result->consumptions, fn ($c) => $c->side === 'left'));
        $this->assertSame([1, 2, 3], array_map(fn ($c) => $c->lotId, $leftCons));
        $this->assertSame(['50.000000', '50.000000', '20.000000'], array_map(fn ($c) => $c->pvConsumed, $leftCons));
        $this->assertTrue($leftCons[0]->exhausted);
        $this->assertTrue($leftCons[1]->exhausted);
        $this->assertFalse($leftCons[2]->exhausted);
        // BV: 4500 + 4500 + floor(9000×20/100) = 4500+4500+1800 = 10800.
        $this->assertSame(10800, $result->leftBvCentsConsumed);
        $this->assertSame(10800, $result->matchedBvCents);
    }

    public function testBvLargestRemainderNoLostCents(): void
    {
        // Нечётный BV: 1001 цент на 3 PV, потребляем 2 PV => точно 667.333… — floor
        // суммы; аллокации сходятся в side-итог без потерянных центов.
        $result = $this->matcher->match(
            [new LotSlice(1, '3.000000', 1001)],
            [new LotSlice(2, '2.000000', 700)],
        );

        $this->assertSame('2.000000', $result->matchedPv);
        $this->assertSame(667, $result->leftBvCentsConsumed); // floor(1001×2/3)
        $this->assertSame(700, $result->rightBvCentsConsumed);
        $this->assertSame(667, $result->matchedBvCents); // min сторон
        $this->assertSame(667, self::sideSum($result->consumptions, 'left'));
        $this->assertSame(700, self::sideSum($result->consumptions, 'right'));
    }

    public function testBvLargestRemainderAcrossSeveralPartialLots(): void
    {
        // Обе стороны с дробными долями: сумма аллокаций стороны строго равна
        // side-итогу (largest remainder), ни цента мимо.
        $result = $this->matcher->match(
            [
                new LotSlice(1, '3.000000', 100), // доля 2/3 → 66.66…
                new LotSlice(2, '3.000000', 100),
            ],
            [new LotSlice(3, '4.000000', 250)],
        );

        // matched = min(6, 4) = 4: лот1 выпит (3PV, 100 центов), лот2 частично 1PV → 33.33…
        $this->assertSame('4.000000', $result->matchedPv);
        // exact: 100 + 100/3 = 133.33… → side 133; аллокации 100 + 33 = 133.
        $this->assertSame(133, $result->leftBvCentsConsumed);
        $this->assertSame(133, self::sideSum($result->consumptions, 'left'));
        // right: 250×4/4 — полный, 250.
        $this->assertSame(250, $result->rightBvCentsConsumed);
        $this->assertSame(133, $result->matchedBvCents);
    }

    public function testDecimalFractionalPv(): void
    {
        $result = $this->matcher->match(
            [new LotSlice(1, '10.500000', 1050)],
            [new LotSlice(2, '7.250000', 725)],
        );

        $this->assertSame('7.250000', $result->matchedPv);
        $this->assertSame(725, $result->leftBvCentsConsumed); // 1050×7.25/10.5 = 725 точно
        $this->assertSame(725, $result->matchedBvCents);
    }

    public function testLeftEmptyZeroMatchFullCarry(): void
    {
        $result = $this->matcher->match([], [new LotSlice(2, '100.000000', 9000)]);

        $this->assertTrue($result->isZero());
        $this->assertSame('left_empty', $result->zeroReason);
        $this->assertSame(0, $result->matchedBvCents);
        $this->assertSame([], $result->consumptions); // полный carry — ничего не потреблено
    }

    public function testBothEmptyZeroMatch(): void
    {
        $result = $this->matcher->match([], []);

        $this->assertTrue($result->isZero());
        $this->assertSame('both_empty', $result->zeroReason);
    }

    public function testRightEmptyZeroMatch(): void
    {
        $result = $this->matcher->match([new LotSlice(1, '10.000000', 1000)], []);

        $this->assertSame('right_empty', $result->zeroReason);
        $this->assertSame([], $result->consumptions);
    }

    public function testEqualSidesConsumeBothToZero(): void
    {
        // Равные стороны: после матчинга обе стороны 0 (роль ветки tie — зона BranchStats).
        $result = $this->matcher->match(
            [new LotSlice(1, '30.000000', 3000)],
            [new LotSlice(2, '30.000000', 3000)],
        );

        foreach ($result->consumptions as $c) {
            $this->assertTrue($c->exhausted);
        }
        $this->assertSame($result->leftBvCentsConsumed, $result->rightBvCentsConsumed);
    }

    public function testExhaustedLotYieldsAllRemainingBvOverLotLifetime(): void
    {
        // Жизненный цикл лота через ДВА прогона: 3 PV / 1000 центов.
        // Прогон 1: потреблено 2 PV → 666 центов (floor). Прогон 2 добивает 1 PV:
        // remaining BV = 334 → лот выпит, отдаёт ВСЕ 334 (Σ за жизнь = ровно 1000).
        $first = $this->matcher->match(
            [new LotSlice(1, '3.000000', 1000)],
            [new LotSlice(2, '2.000000', 500)],
        );
        $firstLeft = self::sideSum($first->consumptions, 'left');
        $this->assertSame(666, $firstLeft); // floor(1000×2/3)

        $second = $this->matcher->match(
            [new LotSlice(1, '1.000000', 1000 - $firstLeft)],
            [new LotSlice(3, '1.000000', 250)],
        );
        $secondLeft = self::sideSum($second->consumptions, 'left');
        $this->assertSame(334, $secondLeft);
        $this->assertSame(1000, $firstLeft + $secondLeft); // ни цента не потеряно
    }

    public function testMatchedBvIsMinOfSideConsumptionsWhenDensitiesDiffer(): void
    {
        // Разная BV-плотность сторон: слева 100PV/10000, справа 100PV/8000 —
        // консервативный min (DEC-016: BV фактических лотов, без переплаты).
        $result = $this->matcher->match(
            [new LotSlice(1, '100.000000', 10000)],
            [new LotSlice(2, '100.000000', 8000)],
        );

        $this->assertSame(10000, $result->leftBvCentsConsumed);
        $this->assertSame(8000, $result->rightBvCentsConsumed);
        $this->assertSame(8000, $result->matchedBvCents);
    }

    public function testResultTypeIntegrity(): void
    {
        $result = $this->matcher->match(
            [new LotSlice(1, '1.000000', 1)],
            [new LotSlice(2, '1.000000', 1)],
        );

        $this->assertInstanceOf(MatchResult::class, $result);
        foreach ($result->consumptions as $c) {
            $this->assertIsInt($c->bvCentsConsumed);
            $this->assertMatchesRegularExpression('/^\d+\.\d{6}$/', $c->pvConsumed);
        }
    }
}
