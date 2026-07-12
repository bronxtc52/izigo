<?php

namespace Modules\Calculator\Tests\Unit\V2\Pool;

use Modules\Calculator\V2\Services\Pool\PoolFactor;
use PHPUnit\Framework\TestCase;

/**
 * T11 [ДЕНЬГИ, ядро формулы]: целочисленная 60%-калибровка (amendments MF-1/2).
 * Только scale-down (factor ≤ 10000), largest-remainder без потери центов, детерминизм,
 * отсутствие float-дрейфа на bigint. Worked example: base 10000 / Σ 10000 → factor 6000.
 */
class PoolFactorTest extends TestCase
{
    public function testWorkedExampleBase10000Sum10000GivesFactor6000(): void
    {
        $f = PoolFactor::forPeriod(baseBvCents: 10000, rateBps: 6000, totalAfterCapsCents: 10000);

        $this->assertSame(6000, $f->poolCapCents);       // 60% от 10000
        $this->assertSame(6000, $f->factorBps);          // min(10000, 6000*10000/10000)
        $this->assertFalse($f->isFull());
        $this->assertSame(6000, $f->scale(10000));       // выплата = 60%
    }

    public function testBelowCapKeepsFullFactor(): void
    {
        // Σ бонусов < 60%·BV → f=1 (никогда scale-up).
        $f = PoolFactor::forPeriod(10000, 6000, 5000);
        $this->assertSame(PoolFactor::FULL_BPS, $f->factorBps);
        $this->assertTrue($f->isFull());
        $this->assertSame(5000, $f->scale(5000)); // без урезания
    }

    public function testExactlySixtyPercentIsFullFactor(): void
    {
        // Ровно 60% → f=1.
        $f = PoolFactor::forPeriod(10000, 6000, 6000);
        $this->assertSame(PoolFactor::FULL_BPS, $f->factorBps);
    }

    public function testAboveCapScalesDownAndRespectsCap(): void
    {
        // Σ = 12000 > cap 6000 → f<1.
        $f = PoolFactor::forPeriod(10000, 6000, 12000);
        $this->assertSame(5000, $f->factorBps); // 6000*10000/12000
        $this->assertLessThan(PoolFactor::FULL_BPS, $f->factorBps);

        $paid = $f->distribute([1 => 6000, 2 => 6000]);
        $this->assertSame(6000, array_sum($paid)); // Σ выплат ровно = pool_cap
        $this->assertLessThanOrEqual($f->poolCapCents, array_sum($paid));
    }

    public function testScaleUpIsImpossibleEvenWhenSumFarBelowCap(): void
    {
        // Крошечный числитель — фактор упирается в 10000, не выше.
        $f = PoolFactor::forPeriod(1_000_000, 6000, 100);
        $this->assertSame(PoolFactor::FULL_BPS, $f->factorBps);
    }

    public function testZeroNumeratorGivesFullFactorNoDivByZero(): void
    {
        $f = PoolFactor::forPeriod(10000, 6000, 0);
        $this->assertSame(PoolFactor::FULL_BPS, $f->factorBps);
        $this->assertSame([], $f->distribute([]));
    }

    public function testZeroBaseBvGivesZeroFactorAllRetained(): void
    {
        // BV=0 при ненулевых бонусах → f=0, всё удержано.
        $f = PoolFactor::forPeriod(0, 6000, 10000);
        $this->assertSame(0, $f->factorBps);
        $paid = $f->distribute([1 => 4000, 2 => 6000]);
        $this->assertSame(0, array_sum($paid));
    }

    public function testLargestRemainderConservesEveryCentDeterministically(): void
    {
        // f=5000; Σraw=1000; target = intdiv(1000*5000,10000)=500.
        $f = PoolFactor::forPeriod(10000, 6000, 12000); // factor 5000
        $raw = [1 => 333, 2 => 333, 3 => 334];
        $paid = $f->distribute($raw);

        $this->assertSame(500, array_sum($paid));
        // Ни цента не теряется: Σraw = Σpaid + Σretained.
        $retained = array_sum($raw) - array_sum($paid);
        $this->assertSame(1000, array_sum($paid) + $retained);
        // Каждый paid ≤ raw.
        foreach ($raw as $k => $r) {
            $this->assertLessThanOrEqual($r, $paid[$k]);
        }
        // Остаток отдан по наибольшей дроби, ничья → ключ ASC (key 1).
        $this->assertSame(167, $paid[1]);
        $this->assertSame(166, $paid[2]);
        $this->assertSame(167, $paid[3]);
        // Детерминизм: повтор даёт то же самое.
        $this->assertSame($paid, $f->distribute($raw));
    }

    public function testNoFloatDriftOnBigIntegers(): void
    {
        // Крупные суммы (bigint), фактор ~80% — целочисленно точно, без float.
        $base = 5_000_000_000; // 50M USD в центах
        $total = 3_750_000_000;
        $f = PoolFactor::forPeriod($base, 6000, $total);
        // cap = 3_000_000_000; factor = intdiv(3_000_000_000*10000, 3_750_000_000) = 8000
        $this->assertSame(3_000_000_000, $f->poolCapCents);
        $this->assertSame(8000, $f->factorBps);

        $raw = [1 => 1_250_000_001, 2 => 1_250_000_000, 3 => 1_249_999_999];
        $paid = $f->distribute($raw);
        $this->assertSame(intdiv($total * 8000, 10000), array_sum($paid));
        $this->assertLessThanOrEqual($f->poolCapCents, array_sum($paid));
    }

    public function testRejectsNegativeInputs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PoolFactor::forPeriod(-1, 6000, 10000);
    }
}
