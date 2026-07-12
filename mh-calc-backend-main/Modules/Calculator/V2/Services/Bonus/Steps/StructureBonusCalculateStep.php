<?php

namespace Modules\Calculator\V2\Services\Bonus\Steps;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Bonus\StructureBonusService;

/**
 * T06: шаг закрытия half-month — РАСЧЁТ структурной премии (raw → индивидуальные
 * капы, status=calculated). Каскад DEC-053: order() РАНЬШЕ 60%-пула T11 и лидерского
 * T08; posting — отдельным поздним шагом (StructureBonusPostStep), чтобы T11 вставил
 * scale-down МЕЖДУ расчётом и проводкой без правки схемы/кода T06.
 *
 * Deny-by-default: supports() гейтит шаг флагом mh_plan_v2_engine (флаг OFF => шаг
 * ВЫРЕЗАН из пайплайна закрытия целиком, не только no-op — иначе он засорял бы
 * step_results соседних задач и требовал активной политики на пустом закрытии).
 *
 * preview-прогон денег/лотов НЕ трогает (мутирует лоты — только боевое закрытие).
 * Метрики детерминированы (без time()).
 */
class StructureBonusCalculateStep implements PeriodCloseStep
{
    /** DEC-053: raw+капы — рано; до пула (T11 ~500) и лидерского (T08 ~600). */
    public const ORDER = 100;

    public function __construct(
        private readonly StructureBonusService $service,
        private readonly FeatureFlagService $flags,
    ) {
    }

    public function supports(string $periodType): bool
    {
        return $periodType === CalcPeriod::TYPE_HALF_MONTH
            && $this->flags->isEnabled('mh_plan_v2_engine');
    }

    public function order(): int
    {
        return self::ORDER;
    }

    public function execute(CalcRun $run, CalcPeriod $period): array
    {
        if ($run->mode === CalcRun::MODE_PREVIEW) {
            return ['step' => 'structure_calculate', 'mode' => 'preview_skipped'];
        }

        return ['step' => 'structure_calculate'] + $this->service->calculateForPeriod($period);
    }
}
