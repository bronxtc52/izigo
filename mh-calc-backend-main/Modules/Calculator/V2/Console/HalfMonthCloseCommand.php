<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;

/**
 * V2 T04: закрытие half-month периодов с внутренним due-check и catch-up —
 * закрывает ЛЮБОЙ просроченный open half-month, не только вчерашний (устойчивость
 * к простоям планировщика). Идемпотентность окна — v2_calc_job_executions.
 */
class HalfMonthCloseCommand extends Command
{
    protected $signature = 'calc-v2:half-month-close';

    protected $description = 'V2: закрыть просроченные half-month периоды (catch-up, идемпотентно)';

    public function handle(FeatureFlagService $flags, PeriodService $periods, PeriodCloseService $closer): int
    {
        if (! $flags->isEnabled('mh_plan_v2_periods')) {
            return self::SUCCESS;
        }

        $periods->ensureCalendar(now()); // строки для пропущенных окон (catch-up)

        $due = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_HALF_MONTH)
            ->whereIn('status', [CalcPeriod::STATUS_OPEN, CalcPeriod::STATUS_CLOSING])
            ->where('ends_at', '<=', now())
            ->orderBy('starts_at')
            ->get();

        $failures = 0;
        foreach ($due as $period) {
            try {
                $closer->closeHalfMonth($period->code);
                $this->info("Закрыт half-month {$period->code}.");
            } catch (\Throwable $e) {
                $failures++;
                $this->error("Закрытие {$period->code} упало: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
