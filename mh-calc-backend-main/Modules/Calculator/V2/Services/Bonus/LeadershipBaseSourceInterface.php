<?php

namespace Modules\Calculator\V2\Services\Bonus;

use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * T08 — ЕДИНСТВЕННАЯ точка стыка лидерского бонуса с T06/T11 (план §630). Отдаёт
 * строки структурной премии, база которых уже финализирована (net_cents ПОСЛЕ капов
 * и 60%-калибровки T11, DEC-029). Когда T11 внесёт калибровку в net-колонку
 * v2_structure_bonuses ДО шага лидерского (DEC-053), реализация НЕ меняется — T08 сам
 * factor_bps не читает и не вычисляет, потребляет уже калиброванный net.
 *
 * Каждый элемент: ['id' => int, 'member_id' => int, 'net_cents' => int] (net > 0;
 * нулевые/reversed строки не порождают лидерский). Для месячного прогона отдаёт строки
 * ОБОИХ half-month окон месяца; для half-month — строки этого окна.
 */
interface LeadershipBaseSourceInterface
{
    /**
     * @return array<int, array{id:int, member_id:int, net_cents:int}>
     */
    public function baseRowsForPeriod(CalcPeriod $period): array;
}
