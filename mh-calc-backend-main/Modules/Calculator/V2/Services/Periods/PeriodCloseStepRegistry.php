<?php

namespace Modules\Calculator\V2\Services\Periods;

use Illuminate\Contracts\Foundation\Application;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;

/**
 * V2 T04: реестр шагов пайплайна закрытия периодов. Шаги (T06/T09/T11) регистрируются
 * tagged-биндингом TAG в CalculatorV2ServiceProvider (маркер-блоки своих задач);
 * порядок исполнения — строго по order() (каскад DEC-053), фильтр — supports(type).
 */
class PeriodCloseStepRegistry
{
    public const TAG = 'calc-v2.period-close-steps';

    /** @var PeriodCloseStep[] программно добавленные шаги (тесты/динамика) */
    private array $extra = [];

    public function __construct(private readonly Application $app)
    {
    }

    public function register(PeriodCloseStep $step): void
    {
        $this->extra[] = $step;
    }

    /** @return PeriodCloseStep[] шаги для типа периода, отсортированные по order() */
    public function stepsFor(string $periodType): array
    {
        $steps = array_merge(
            iterator_to_array($this->app->tagged(self::TAG), false),
            $this->extra,
        );

        $steps = array_values(array_filter(
            $steps,
            fn (PeriodCloseStep $step) => $step->supports($periodType),
        ));

        usort($steps, fn (PeriodCloseStep $a, PeriodCloseStep $b) => $a->order() <=> $b->order());

        return $steps;
    }
}
