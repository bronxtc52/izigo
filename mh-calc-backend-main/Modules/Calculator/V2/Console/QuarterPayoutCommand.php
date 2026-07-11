<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\PeriodCloseBlockedException;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;

/**
 * V2 T04: квартальная выплата глобального пула + закрытие квартала. Предикат:
 * квартал закончился и все 3 месяца закрыты — иначе job FAILED (BLOCKED) без
 * постинга, квартал остаётся open. QuarterGlobalPayoutHandler вызывается ровно
 * один раз на окно (идемпотентность 'quarter-payout' + код квартала);
 * реализацию даёт T09 (до неё Null — квартал закрывается без выплаты).
 */
class QuarterPayoutCommand extends Command
{
    protected $signature = 'calc-v2:quarter-payout';

    protected $description = 'V2: закрыть просроченные кварталы с выплатой глобального пула (после 3 закрытых месяцев)';

    public function handle(FeatureFlagService $flags, PeriodService $periods, PeriodCloseService $closer): int
    {
        if (! $flags->isEnabled('mh_plan_v2_periods')) {
            return self::SUCCESS;
        }

        $periods->ensureCalendar(now());

        $due = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_QUARTER)
            ->whereIn('status', [CalcPeriod::STATUS_OPEN, CalcPeriod::STATUS_CLOSING])
            ->where('ends_at', '<=', now())
            ->orderBy('starts_at')
            ->get();

        $failures = 0;
        foreach ($due as $period) {
            try {
                $closer->closeQuarter($period->code);
                $this->info("Квартал {$period->code} закрыт (глобальный пул проведён handler'ом).");
            } catch (PeriodCloseBlockedException $e) {
                $this->warn($e->getMessage());
            } catch (\Throwable $e) {
                $failures++;
                $this->error("Квартал {$period->code} упал: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
