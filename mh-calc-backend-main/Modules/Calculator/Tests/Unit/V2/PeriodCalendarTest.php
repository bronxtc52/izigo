<?php

namespace Modules\Calculator\Tests\Unit\V2;

use Carbon\CarbonImmutable;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\PeriodCalendar;
use PHPUnit\Framework\TestCase;

/**
 * T04: границы расчётных периодов — UTC, полуоткрытые интервалы [start, end).
 * Критично для денег: событие ровно 16-го 00:00:00 принадлежит ТОЛЬКО H2,
 * ровно 1-го 00:00 — только новому месяцу (API-TIME-01).
 */
class PeriodCalendarTest extends TestCase
{
    private PeriodCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calendar = new PeriodCalendar();
    }

    private static function utc(string $ts): CarbonImmutable
    {
        return CarbonImmutable::parse($ts, 'UTC');
    }

    public function testHalfMonthBoundariesFor31DayMonth(): void
    {
        $h1 = $this->calendar->halfMonthFor(self::utc('2026-07-03 12:00:00'));
        $this->assertSame('2026-07-H1', $h1->code);
        $this->assertSame('2026-07-01 00:00:00', $h1->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 00:00:00', $h1->endsAt->format('Y-m-d H:i:s'));

        $h2 = $this->calendar->halfMonthFor(self::utc('2026-07-31 23:59:59'));
        $this->assertSame('2026-07-H2', $h2->code);
        $this->assertSame('2026-08-01 00:00:00', $h2->endsAt->format('Y-m-d H:i:s'));
    }

    public function testHalfMonthBoundariesForFebruaryAndLeapYear(): void
    {
        // Обычный февраль (28 дней).
        $h2 = $this->calendar->halfMonthFor(self::utc('2026-02-20 00:00:00'));
        $this->assertSame('2026-02-H2', $h2->code);
        $this->assertSame('2026-02-16 00:00:00', $h2->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-01 00:00:00', $h2->endsAt->format('Y-m-d H:i:s'));

        // Високосный 2028 (29 дней): H2 всё равно кончается 1-го марта 00:00.
        $h2leap = $this->calendar->halfMonthFor(self::utc('2028-02-29 23:59:59'));
        $this->assertSame('2028-02-H2', $h2leap->code);
        $this->assertSame('2028-03-01 00:00:00', $h2leap->endsAt->format('Y-m-d H:i:s'));
    }

    public function testHalfMonthBoundariesFor30DayMonth(): void
    {
        $h2 = $this->calendar->halfMonthFor(self::utc('2026-04-30 10:00:00'));
        $this->assertSame('2026-04-H2', $h2->code);
        $this->assertSame('2026-05-01 00:00:00', $h2->endsAt->format('Y-m-d H:i:s'));
    }

    /** ДЕНЬГИ/API-TIME-01: ровно 16-е 00:00:00 — только H2; 15-е 23:59:59 — H1. */
    public function testExactMidMonthBoundaryBelongsOnlyToH2(): void
    {
        $atBoundary = $this->calendar->halfMonthFor(self::utc('2026-07-16 00:00:00'));
        $this->assertSame('2026-07-H2', $atBoundary->code);

        $justBefore = $this->calendar->halfMonthFor(self::utc('2026-07-15 23:59:59'));
        $this->assertSame('2026-07-H1', $justBefore->code);

        $h1 = $this->calendar->fromCode('2026-07-H1');
        $this->assertFalse($h1->contains(self::utc('2026-07-16 00:00:00')));
        $this->assertTrue($h1->contains(self::utc('2026-07-15 23:59:59')));
        $this->assertTrue($atBoundary->contains(self::utc('2026-07-16 00:00:00')));
    }

    /** Ровно 1-е 00:00 принадлежит только НОВОМУ месяцу (и его H1). */
    public function testExactMonthStartBelongsOnlyToNewMonth(): void
    {
        $month = $this->calendar->monthFor(self::utc('2026-08-01 00:00:00'));
        $this->assertSame('2026-08', $month->code);

        $july = $this->calendar->fromCode('2026-07');
        $this->assertFalse($july->contains(self::utc('2026-08-01 00:00:00')));

        $h1 = $this->calendar->halfMonthFor(self::utc('2026-08-01 00:00:00'));
        $this->assertSame('2026-08-H1', $h1->code);
    }

    public function testCalendarQuarters(): void
    {
        $q3 = $this->calendar->quarterFor(self::utc('2026-08-15 12:00:00'));
        $this->assertSame('2026-Q3', $q3->code);
        $this->assertSame('2026-07-01 00:00:00', $q3->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 00:00:00', $q3->endsAt->format('Y-m-d H:i:s'));

        $q1 = $this->calendar->quarterFor(self::utc('2027-01-01 00:00:00'));
        $this->assertSame('2027-Q1', $q1->code);

        // Граница кварталов: ровно 1 октября — уже Q4.
        $q4 = $this->calendar->quarterFor(self::utc('2026-10-01 00:00:00'));
        $this->assertSame('2026-Q4', $q4->code);
    }

    public function testPreviousAndNextAreDeterministicRoundTrips(): void
    {
        foreach (['2026-07-H1', '2026-07-H2', '2026-07', '2026-Q3'] as $code) {
            $window = $this->calendar->fromCode($code);
            $this->assertSame($code, $window->code, 'fromCode/code round-trip');
            $this->assertSame(
                $code,
                $this->calendar->nextOf($this->calendar->previousOf($window))->code,
                'previousOf→nextOf round-trip'
            );
        }

        // Через границу месяца/года.
        $this->assertSame('2026-06-H2', $this->calendar->previousOf($this->calendar->fromCode('2026-07-H1'))->code);
        $this->assertSame('2025-Q4', $this->calendar->previousOf($this->calendar->fromCode('2026-Q1'))->code);
        $this->assertSame('2027-01', $this->calendar->nextOf($this->calendar->fromCode('2026-12'))->code);
    }

    public function testQuarterAndMonthHelpers(): void
    {
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $this->calendar->monthCodesOfQuarter('2026-Q3'));
        $this->assertSame(['2026-07-H1', '2026-07-H2'], $this->calendar->halfCodesOfMonth('2026-07'));
    }

    public function testWindowForRejectsUnknownTypeAndCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calendar->windowFor('week', self::utc('2026-07-01'));
    }

    public function testFromCodeRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calendar->fromCode('2026-13-H9');
    }

    public function testWindowForDispatchesAllTypes(): void
    {
        $at = self::utc('2026-07-20 10:00:00');
        $this->assertSame('2026-07-H2', $this->calendar->windowFor(CalcPeriod::TYPE_HALF_MONTH, $at)->code);
        $this->assertSame('2026-07', $this->calendar->windowFor(CalcPeriod::TYPE_MONTH, $at)->code);
        $this->assertSame('2026-Q3', $this->calendar->windowFor(CalcPeriod::TYPE_QUARTER, $at)->code);
    }
}
