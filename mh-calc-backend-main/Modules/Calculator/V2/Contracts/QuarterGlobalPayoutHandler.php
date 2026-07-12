<?php

namespace Modules\Calculator\V2\Contracts;

use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * V2: квартальная выплата глобального пула (реализация — T09; Null-дефолт — T04,
 * биндинг перебивается в CalculatorV2ServiceProvider маркер-блоком T09).
 *
 * Вызывается ровно один раз на окно квартала (идемпотентность окна — job
 * `calc-v2:quarter-payout` T04 через v2_calc_job_executions), внутри транзакции
 * закрытия квартала под advisory-lock. Предикат T04: все 3 месяца квартала закрыты.
 */
interface QuarterGlobalPayoutHandler
{
    /**
     * Провести квартальную выплату глобального пула.
     *
     * @param  CalcPeriod $quarter        закрываемый квартал (status=closing)
     * @param  int[]      $monthPeriodIds id трёх ЗАКРЫТЫХ месячных периодов квартала
     * @param  string     $windowKey      ключ окна идемпотентности (напр. '2026-Q3')
     * @return array метрики для step_results (только детерминированные значения)
     */
    public function payQuarter(CalcPeriod $quarter, array $monthPeriodIds, string $windowKey): array;
}
