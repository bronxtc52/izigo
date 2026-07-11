<?php

namespace Modules\Calculator\V2\Services\Periods;

use Carbon\CarbonImmutable;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\PeriodWindow;

/**
 * V2 T04: чистый калькулятор границ расчётных периодов. Все границы — UTC
 * (осознанное отступление от Asia/Almaty спеки, роадмап T04; колонка timezone
 * оставлена на будущее), интервалы полуоткрытые [start, end):
 * H1 = [1-е 00:00, 16-е 00:00), H2 = [16-е 00:00, 1-е след. месяца 00:00);
 * событие ровно 16-го 00:00:00 принадлежит ТОЛЬКО H2, ровно 1-го — новому месяцу
 * (API-TIME-01). Кварталы календарные (DEC-036 стартово).
 */
class PeriodCalendar
{
    /** Окно указанного типа, содержащее момент $at. */
    public function windowFor(string $type, \DateTimeInterface $at): PeriodWindow
    {
        return match ($type) {
            CalcPeriod::TYPE_HALF_MONTH => $this->halfMonthFor($at),
            CalcPeriod::TYPE_MONTH => $this->monthFor($at),
            CalcPeriod::TYPE_QUARTER => $this->quarterFor($at),
            default => throw new \InvalidArgumentException("Неизвестный тип периода: {$type}"),
        };
    }

    public function halfMonthFor(\DateTimeInterface $at): PeriodWindow
    {
        $at = $this->utc($at);
        $monthStart = $at->startOfMonth();
        $mid = $monthStart->addDays(15); // 16-е 00:00

        if ($at < $mid) {
            return new PeriodWindow(
                CalcPeriod::TYPE_HALF_MONTH,
                $monthStart->format('Y-m') . '-H1',
                $monthStart,
                $mid,
            );
        }

        return new PeriodWindow(
            CalcPeriod::TYPE_HALF_MONTH,
            $monthStart->format('Y-m') . '-H2',
            $mid,
            $monthStart->addMonthNoOverflow()->startOfMonth(),
        );
    }

    public function monthFor(\DateTimeInterface $at): PeriodWindow
    {
        $at = $this->utc($at);
        $start = $at->startOfMonth();

        return new PeriodWindow(
            CalcPeriod::TYPE_MONTH,
            $start->format('Y-m'),
            $start,
            $start->addMonthNoOverflow()->startOfMonth(),
        );
    }

    public function quarterFor(\DateTimeInterface $at): PeriodWindow
    {
        $at = $this->utc($at);
        $start = $at->startOfQuarter();

        return new PeriodWindow(
            CalcPeriod::TYPE_QUARTER,
            $start->format('Y') . '-Q' . $start->quarter,
            $start,
            $start->addQuarter()->startOfQuarter(),
        );
    }

    /** Предыдущее окно того же типа. */
    public function previousOf(PeriodWindow $window): PeriodWindow
    {
        return $this->windowFor($window->type, $window->startsAt->subSecond());
    }

    /** Следующее окно того же типа. */
    public function nextOf(PeriodWindow $window): PeriodWindow
    {
        return $this->windowFor($window->type, $window->endsAt);
    }

    /**
     * Окно по коду: '2026-07-H1'/'2026-07-H2' (half-month), '2026-07' (month),
     * '2026-Q3' (quarter). Детерминированный обратный маппинг codeFor.
     */
    public function fromCode(string $code): PeriodWindow
    {
        // Месяц строго 01..12 (примечание ревью W1 #1): '(\d{2})' пропускал '2026-13',
        // который Carbon нормализует в 2027-01 — окно не того месяца.
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-H([12])$/', $code, $m)) {
            $anchor = CarbonImmutable::create((int) $m[1], (int) $m[2], $m[3] === '1' ? 1 : 16, 0, 0, 0, 'UTC');

            return $this->halfMonthFor($anchor);
        }

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $code, $m)) {
            return $this->monthFor(CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC'));
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $code, $m)) {
            $month = ((int) $m[2] - 1) * 3 + 1;

            return $this->quarterFor(CarbonImmutable::create((int) $m[1], $month, 1, 0, 0, 0, 'UTC'));
        }

        throw new \InvalidArgumentException("Нераспознанный код периода: {$code}");
    }

    /** Коды двух half-month месяца 'YYYY-MM'. */
    public function halfCodesOfMonth(string $monthCode): array
    {
        return ["{$monthCode}-H1", "{$monthCode}-H2"];
    }

    /** Коды трёх месяцев квартала 'YYYY-Qn'. */
    public function monthCodesOfQuarter(string $quarterCode): array
    {
        $window = $this->fromCode($quarterCode);
        $codes = [];
        for ($i = 0; $i < 3; $i++) {
            $codes[] = $window->startsAt->addMonthsNoOverflow($i)->format('Y-m');
        }

        return $codes;
    }

    private function utc(\DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance(\DateTime::createFromInterface($at))->utc();
    }
}
