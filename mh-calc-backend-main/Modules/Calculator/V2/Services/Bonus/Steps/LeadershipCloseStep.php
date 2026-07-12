<?php

namespace Modules\Calculator\V2\Services\Bonus\Steps;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Bonus\LeadershipBonusService;

/**
 * T08 — шаг MONTH-close: лидерский бонус (CAL-LED-001) от финализированной структурной
 * премии месяца. Каскад DEC-053: raw → капы → 60%-пул → ЛИДЕРСКИЙ → posting. База
 * (DEC-029) = net структурной премии ПОСЛЕ капов и 60%-калибровки, значит шаг ОБЯЗАН
 * идти СТРОГО ПОСЛЕ шага калибровки T11.
 *
 * КОНТРАКТ ПОРЯДКА (план §644/§650): T11.order() < LeadershipCloseStep::ORDER — T11
 * пишет калиброванный net в v2_structure_bonuses ДО этого шага. ORDER=800 (после
 * калибровки T11 ∈ (300,900), до финализации глобального T09 = 900). Лидерский сам в
 * числитель 60%-калибровки не входит (calibration.include.leadership=false, DEC-029 —
 * иначе цикл), поэтому идёт ПОСЛЕ неё без итерации.
 *
 * Отклонение от буквы плана (§632 «закрытие half-month»): т.к. amendments MF-4 сделали
 * 60%-калибровку МЕСЯЧНОЙ, а DEC-029 требует пост-калибровочную базу, лидерский
 * финализируется на MONTH-close (иначе база не калибрована). Half-month не поддерживается
 * этим шагом (ручной прогон — команда calculator:v2:leadership-run).
 *
 * Гейт mh_v2_leadership (deny-by-default): OFF ⇒ no-op. Preview-прогон не персистит.
 */
class LeadershipCloseStep implements PeriodCloseStep
{
    public const FLAG = 'mh_v2_leadership';
    public const ORDER = 800;

    public function __construct(
        private readonly LeadershipBonusService $service,
        private readonly FeatureFlagService $flags,
    ) {
    }

    public function supports(string $periodType): bool
    {
        return $periodType === CalcPeriod::TYPE_MONTH;
    }

    public function order(): int
    {
        return self::ORDER;
    }

    public function execute(CalcRun $run, CalcPeriod $period): array
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return ['step' => 'leadership', 'skipped' => 'flag_off'];
        }
        if ($run->mode === CalcRun::MODE_PREVIEW) {
            return ['step' => 'leadership', 'skipped' => 'preview'];
        }

        $metrics = $this->service->runForPeriod($period);

        return array_merge(['step' => 'leadership'], $metrics);
    }
}
