<?php

namespace Modules\Calculator\V2\Contracts;

use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * V2: шаг пайплайна закрытия периода (реестр и оркестратор — T04
 * PeriodCloseService/PeriodCloseStepRegistry; сами шаги регистрируют T06/T09/T11
 * tagged-биндингом PeriodCloseStepRegistry::TAG в CalculatorV2ServiceProvider).
 *
 * МЕЖЗАДАЧНЫЙ КОНТРАКТ (план T04, заморожен на Гейте A): менять сигнатуру после
 * старта T06 нельзя. Порядок каскада DEC-053 (raw → капы → 60%-пул → лидерский →
 * posting) кодируется константами order() у шагов.
 *
 * Шаги исполняются ВНУТРИ транзакции закрытия под advisory-lock ACTIVATION_LOCK_KEY;
 * исключение в шаге откатывает ВСЁ закрытие (run failed, период остаётся open,
 * постингов нет — частичного closed не существует).
 */
interface PeriodCloseStep
{
    /** Применим ли шаг к типу периода ('half_month'|'month'|'quarter'). */
    public function supports(string $periodType): bool;

    /** Порядок в каскаде DEC-053: меньше — раньше. */
    public function order(): int;

    /**
     * Выполнить шаг. Возвращает метрики шага (пишутся в calc_runs.step_results
     * и участвуют в result_hash — только детерминированные значения, без time()).
     */
    public function execute(CalcRun $run, CalcPeriod $period): array;
}
