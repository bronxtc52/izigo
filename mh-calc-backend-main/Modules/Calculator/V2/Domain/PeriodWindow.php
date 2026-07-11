<?php

namespace Modules\Calculator\V2\Domain;

use Carbon\CarbonImmutable;

/**
 * V2 T04: чистое значение «окно расчётного периода» — тип, код и полуоткрытый
 * интервал [start, end) в UTC. Производится PeriodCalendar, потребляется
 * PeriodService (ленивое создание строк v2_calc_periods).
 */
final class PeriodWindow
{
    public function __construct(
        public readonly string $type,
        public readonly string $code,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {
    }

    /** Принадлежит ли момент окну (полуоткрытый интервал: start включён, end — нет). */
    public function contains(\DateTimeInterface $at): bool
    {
        $at = CarbonImmutable::instance(\DateTime::createFromInterface($at))->utc();

        return $at >= $this->startsAt && $at < $this->endsAt;
    }

    /** Окно уже целиком в прошлом (можно закрывать). */
    public function endedBy(\DateTimeInterface $now): bool
    {
        return CarbonImmutable::instance(\DateTime::createFromInterface($now))->utc() >= $this->endsAt;
    }
}
