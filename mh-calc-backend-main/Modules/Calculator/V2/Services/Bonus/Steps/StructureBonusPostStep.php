<?php

namespace Modules\Calculator\V2\Services\Bonus\Steps;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Bonus\StructureBonusPostingService;

/**
 * T06: шаг закрытия half-month — POSTING структурной премии на НС (двойная запись).
 * Каскад DEC-053: order() ПОЗЖЕ 60%-пула T11 (~500) и базы лидерского T08 (~600) —
 * деньги на НС проводятся уже с учётом net (сейчас net = after_cap, до T11).
 *
 * Deny-by-default: supports() гейтит шаг флагом mh_plan_v2_engine (парно с calculate-
 * шагом; флаг OFF => шаг вырезан из пайплайна).
 *
 * preview-прогон денег НЕ постит. Метрики детерминированы.
 */
class StructureBonusPostStep implements PeriodCloseStep
{
    /** DEC-053: posting — финальная фаза каскада (после пула T11 и лидерского T08). */
    public const ORDER = 900;

    public function __construct(
        private readonly StructureBonusPostingService $posting,
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
            return ['step' => 'structure_post', 'mode' => 'preview_skipped'];
        }

        return ['step' => 'structure_post'] + $this->posting->postForPeriod($period);
    }
}
