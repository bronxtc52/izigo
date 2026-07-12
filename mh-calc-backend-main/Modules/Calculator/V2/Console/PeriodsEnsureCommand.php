<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Services\Periods\PeriodService;

/**
 * V2 T04: идемпотентно создать текущие + следующие расчётные периоды всех трёх
 * типов (+catch-up пропущенных окон после простоя). Гейт: флаг mh_plan_v2_periods
 * (deny-by-default) — до включения V2 прод-поведение не меняется.
 */
class PeriodsEnsureCommand extends Command
{
    protected $signature = 'calc-v2:periods-ensure';

    protected $description = 'V2: создать текущие и следующие расчётные периоды (half-month/month/quarter), идемпотентно';

    public function handle(FeatureFlagService $flags, PeriodService $periods): int
    {
        if (! $flags->isEnabled('mh_plan_v2_periods')) {
            return self::SUCCESS; // немедленный no-op при выключенном флаге
        }

        $created = $periods->ensureCalendar(now());
        $this->info("V2-периоды обеспечены (новых строк: {$created}).");

        return self::SUCCESS;
    }
}
