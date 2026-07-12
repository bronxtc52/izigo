<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Bonus\StructureBonusPostingService;
use Modules\Calculator\V2\Services\Bonus\StructureBonusService;

/**
 * T06: ручной идемпотентный пере-прогон расчёта+posting структурной премии окна
 * (диагностика/восстановление). В расписание НЕ ставится — боевой триггер закрытия
 * окна принадлежит T04 (close-pipeline через PeriodCloseStep). Гейтится
 * mh_plan_v2_engine (deny-by-default). Берёт advisory-lock активаций (единый порядок
 * локов с оркестратором закрытия), затем calc → post.
 */
class StructureBonusRunCommand extends Command
{
    protected $signature = 'v2:structure-bonus:run {period : код half-month периода, напр. 2026-07-H1}';

    protected $description = 'V2: пересчитать и провести структурную премию окна half-month (идемпотентно)';

    public function handle(
        FeatureFlagService $flags,
        StructureBonusService $service,
        StructureBonusPostingService $posting,
        ActivationService $activation,
    ): int {
        if (! $flags->isEnabled('mh_plan_v2_engine')) {
            $this->warn('Флаг mh_plan_v2_engine выключен — расчёт V2 недоступен (deny-by-default).');

            return self::SUCCESS;
        }

        $code = (string) $this->argument('period');
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_HALF_MONTH)
            ->where('code', $code)
            ->first();

        if ($period === null) {
            $this->error("Half-month период {$code} не найден в v2_calc_periods.");

            return self::FAILURE;
        }

        try {
            [$calc, $post] = DB::transaction(function () use ($service, $posting, $activation, $period) {
                $activation->acquireActivationLock(); // единый порядок локов с закрытием периода

                return [
                    $service->calculateForPeriod($period),
                    $posting->postForPeriod($period),
                ];
            });
        } catch (\Modules\Calculator\V2\Services\Periods\ClosedPeriodException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Структурная премия %s: eligible=%d, gross=%d, after_cap=%d, forfeited=%d, posted=%d, НС=%d центов.',
            $code, $calc['eligible'], $calc['gross_cents'], $calc['after_cap_cents'],
            $calc['forfeited_cents'], $post['posted'], $post['ns_credited_cents'],
        ));

        return self::SUCCESS;
    }
}
