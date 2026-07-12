<?php

namespace Modules\Calculator\Tests\Unit\V2\Bonus;

use Modules\Calculator\V2\Domain\Bonus\StructureBonusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * T06 [ДЕНЬГИ, обязательный]: чистая математика структурной премии — целочисленно,
 * floor на финальной сумме (DEC-002). Голдены CAL-BIN-001 в USD-центах по курсу 468,
 * капы (полумесячный + месячная safety), сгорание сверх капа (решение владельца),
 * округление, граничные. Без БД/времени.
 */
class StructureBonusCalculatorTest extends TestCase
{
    private StructureBonusCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new StructureBonusCalculator();
    }

    /** CAL-BIN-001 #1: 100PV vs 100PV, Manager 5%, BV 42120 KZT → 9000 центов → премия 450. */
    public function testGoldenEqualLegsManagerFivePercent(): void
    {
        // MANAGER: rate 500 bps, half 50000, monthly 100000; matched_bv 9000.
        $r = $this->calc->compute(9000, 500, 50000, 100000, 0);

        $this->assertSame(450, $r->grossCents);
        $this->assertSame(450, $r->afterCapCents);
        $this->assertSame(0, $r->forfeitedCents);
        $this->assertSame(100000, $r->capRemainingBeforeCents);
    }

    /** CAL-BIN-001 #2: 100 vs 50 free PV → matched_bv 4500 → премия 225 (carryover в T03). */
    public function testGoldenPartialMatch(): void
    {
        $r = $this->calc->compute(4500, 500, 50000, 100000, 0);

        $this->assertSame(225, $r->grossCents);
        $this->assertSame(225, $r->afterCapCents);
        $this->assertSame(0, $r->forfeitedCents);
    }

    /** Полумесячный кап: gross > half_cap → after_cap = half_cap, сматченный сверх СГОРАЕТ. */
    public function testHalfMonthCapClampsAndForfeitsExcess(): void
    {
        // matched_bv 1_200_000 * 5% = 60000 gross > half_cap 50000.
        $r = $this->calc->compute(1_200_000, 500, 50000, 100000, 0);

        $this->assertSame(60000, $r->grossCents);
        $this->assertSame(50000, $r->afterCapCents);
        $this->assertSame(10000, $r->forfeitedCents); // сгоревшая дельта видна
    }

    /** Месячная safety: второе окно ограничено остатком месячного капа (Σ after_cap ≤ monthly). */
    public function testMonthlySafetyAcrossWindows(): void
    {
        // H1 уже использовал 50000 из monthly 100000; H2 gross 60000 → after_cap 50000.
        $r = $this->calc->compute(1_200_000, 500, 50000, 100000, 50000);

        $this->assertSame(60000, $r->grossCents);
        $this->assertSame(50000, $r->capRemainingBeforeCents); // остаток месяца ДО окна
        $this->assertSame(50000, $r->afterCapCents);
        $this->assertSame(10000, $r->forfeitedCents);
        // Σ after_cap двух окон = 50000 + 50000 = 100000 = monthly cap.
    }

    /** Месячный кап исчерпан ранее → after_cap 0, весь gross сгорает. */
    public function testMonthlyExhaustedYieldsZeroAfterCap(): void
    {
        $r = $this->calc->compute(100_000, 500, 50000, 100000, 100000);

        $this->assertSame(5000, $r->grossCents);
        $this->assertSame(0, $r->capRemainingBeforeCents);
        $this->assertSame(0, $r->afterCapCents);
        $this->assertSame(5000, $r->forfeitedCents);
    }

    /** Половина окна ниже half-cap, но месячный остаток меньше — берётся месячная граница. */
    public function testMonthlyBoundTighterThanHalfCap(): void
    {
        // gross 30000 < half_cap 50000, но monthly_remaining = 100000-80000 = 20000.
        $r = $this->calc->compute(600_000, 500, 50000, 100000, 80000);

        $this->assertSame(30000, $r->grossCents);
        $this->assertSame(20000, $r->afterCapCents);
        $this->assertSame(10000, $r->forfeitedCents);
    }

    /** Округление: 6% от нечётного matched_bv → детерминированный floor, без субцентов. */
    public function testRoundingFloorsToCent(): void
    {
        // GOLD_MANAGER 600 bps; 333 * 600 / 10000 = 19.98 → 19.
        $r = $this->calc->compute(333, 600, 250000, 500000, 0);
        $this->assertSame(19, $r->grossCents);

        // 9999 * 900 / 10000 = 899.91 → 899 (VICE_PRESIDENT 9%).
        $r2 = $this->calc->compute(9999, 900, 2_000_000, 4_000_000, 0);
        $this->assertSame(899, $r2->grossCents);
    }

    /** Нулевой matched_bv (нет матча / одна нога) → gross 0, ничего не сгорает. */
    public function testZeroMatchedBvYieldsZero(): void
    {
        $r = $this->calc->compute(0, 500, 50000, 100000, 0);

        $this->assertSame(0, $r->grossCents);
        $this->assertSame(0, $r->afterCapCents);
        $this->assertSame(0, $r->forfeitedCents);
    }

    /** Нулевая ставка (CLIENT, если бы дошёл) → gross 0. */
    public function testZeroRateYieldsZero(): void
    {
        $r = $this->calc->compute(1_000_000, 0, 0, 0, 0);
        $this->assertSame(0, $r->grossCents);
        $this->assertSame(0, $r->afterCapCents);
    }

    /** Целочисленная математика: результат не переполняется на крупных суммах (VP). */
    public function testLargeAmountsStayInteger(): void
    {
        // matched_bv 400_000_000 центов ($4M) * 9% = 36_000_000; capped monthly 4_000_000.
        $r = $this->calc->compute(400_000_000, 900, 9_360_000, 18_720_000, 0);
        $this->assertSame(36_000_000, $r->grossCents);
        $this->assertSame(9_360_000, $r->afterCapCents); // half-cap связывает
        $this->assertSame(26_640_000, $r->forfeitedCents);
        $this->assertIsInt($r->grossCents);
    }

    public function testNegativeInputRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calc->compute(-1, 500, 50000, 100000, 0);
    }
}
