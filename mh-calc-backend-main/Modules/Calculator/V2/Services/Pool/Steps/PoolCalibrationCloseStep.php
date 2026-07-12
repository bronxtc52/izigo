<?php

namespace Modules\Calculator\V2\Services\Pool\Steps;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Pool\PoolCalibrationService;

/**
 * T11 — шаг month-close: 60%-калибровка (DEC-053). Каскад по order(): global allocate
 * (T09, 300) → 60%-калибровка (500) → global finalize (T09, 900). Между allocate и
 * finalize: T11 читает structure-after-caps + global capped, коммитит factor_bps и
 * пишет global final_cents ДО заморозки месяца. Структурную НС→ОС по этому factor_bps
 * переводит уже T04 NsToOsTransfer (следующий джоб), поэтому здесь денег на счета не постим.
 *
 * Гейт mh_v2_pool (deny-by-default): OFF ⇒ no-op. Preview-прогон ничего не персистит.
 * Метрики детерминированы (входят в result_hash — без time()).
 */
class PoolCalibrationCloseStep implements PeriodCloseStep
{
    public const FLAG = 'mh_v2_pool';
    public const ORDER = 500;

    public function __construct(
        private readonly PoolCalibrationService $service,
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
            return ['step' => 'pool_calibrate', 'skipped' => 'flag_off'];
        }
        if ($run->mode === CalcRun::MODE_PREVIEW) {
            return ['step' => 'pool_calibrate', 'skipped' => 'preview'];
        }

        $cal = $this->service->calibrateMonth($period);

        return [
            'step' => 'pool_calibrate',
            'month' => $cal->month,
            'run_version' => $cal->run_version,
            'base_bv_cents' => $cal->base_bv_cents,
            'pool_cap_cents' => $cal->pool_cap_cents,
            'total_after_caps_cents' => $cal->total_after_caps_cents,
            'factor_bps' => $cal->factor_bps,
            'scaled_total_cents' => $cal->scaled_total_cents,
            'company_retained_cents' => $cal->company_retained_cents,
        ];
    }
}
