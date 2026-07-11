<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\PeriodCloseBlockedException;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;

/**
 * V2 T04: закрытие просроченных месяцев. Предикат: оба half-month закрыты —
 * иначе job FAILED (BLOCKED) без постингов, период остаётся open, ретрай
 * следующим тиком. Catch-up как у half-month-close.
 */
class MonthCloseCommand extends Command
{
    protected $signature = 'calc-v2:month-close';

    protected $description = 'V2: закрыть просроченные month периоды (только после обоих half-month)';

    public function handle(FeatureFlagService $flags, PeriodService $periods, PeriodCloseService $closer): int
    {
        if (! $flags->isEnabled('mh_plan_v2_periods')) {
            return self::SUCCESS;
        }

        $periods->ensureCalendar(now());

        $due = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->whereIn('status', [CalcPeriod::STATUS_OPEN, CalcPeriod::STATUS_CLOSING])
            ->where('ends_at', '<=', now())
            ->orderBy('starts_at')
            ->get();

        $failures = 0;
        foreach ($due as $period) {
            try {
                $closer->closeMonth($period->code);
                $this->info("Закрыт месяц {$period->code}.");
            } catch (PeriodCloseBlockedException $e) {
                $this->warn($e->getMessage()); // ожидаемое состояние, не алёрт
            } catch (\Throwable $e) {
                $failures++;
                $this->error("Закрытие {$period->code} упало: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
