<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;

/**
 * T09 — шаг month-close: финализация месяца глобального бонуса (draft → final).
 * Порядок ПОСЛЕ 60%-калибровки T11 (T11 перезаписывает final_cents ДО финализации,
 * plan T09 line 719/725). Финальный месяц замораживается: квартальная выплата берёт
 * его final_cents; пересчёт запрещён. supports только month; идемпотентно.
 */
class GlobalBonusFinalizeStep implements PeriodCloseStep
{
    public const FLAG = 'mh_v2_global_bonus';
    public const ORDER = 900;

    public function __construct(
        private readonly GlobalBonusMonthlyService $service,
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
            return ['step' => 'global_finalize', 'skipped' => 'flag_off'];
        }
        if ($run->mode === CalcRun::MODE_PREVIEW) {
            return ['step' => 'global_finalize', 'skipped' => 'preview'];
        }

        $month = $this->service->finalizeMonth($period);

        return [
            'step' => 'global_finalize',
            'status' => $month?->status,
            'month_period_id' => $period->id,
        ];
    }
}
