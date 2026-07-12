<?php

namespace Modules\Calculator\V2\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Pool\PoolCalibrationService;

/**
 * T11 — ручной/аварийный запуск 60%-калибровки месяца: calc-v2:pool-calibrate {month?}.
 * Регулярный вызов — шаг month-close джобы T04 (PoolCalibrationCloseStep, MF-6: расписание
 * периодов у T04). Эта команда — ручной пересчёт draft-месяца админом. Закрытый месяц →
 * отказ (корректировки закрытого периода — контур T12). Флаг OFF → no-op.
 */
class PoolCalibrateCommand extends Command
{
    public const FLAG = 'mh_v2_pool';

    protected $signature = 'calc-v2:pool-calibrate {month? : Код месяца YYYY-MM (по умолчанию прошлый месяц UTC)}';

    protected $description = 'T11: (пере)расчёт 60%-калибровки draft-месяца (factor_bps, global final_cents)';

    public function handle(PoolCalibrationService $service, FeatureFlagService $flags): int
    {
        if (! $flags->isEnabled(self::FLAG)) {
            $this->warn('Флаг mh_v2_pool выключен — no-op.');

            return self::SUCCESS;
        }

        $code = $this->argument('month') ?? CarbonImmutable::now('UTC')->subMonthNoOverflow()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $code)) {
            $this->error("Некорректный код месяца: {$code} (ожидается YYYY-MM).");

            return self::FAILURE;
        }

        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->where('code', $code)
            ->first();
        if ($period === null) {
            $this->error("Месячный период {$code} не найден (создаётся джобом периодов T04).");

            return self::FAILURE;
        }
        if ($period->isClosed()) {
            $this->error("Месяц {$code} закрыт — рекалибровка недоступна (корректировки — контур возвратов T12).");

            return self::FAILURE;
        }

        $cal = $service->calibrateMonth($period, 'cli');
        $this->info(sprintf(
            '60%%-калибровка %s: run=%d, base_bv=%d, числитель=%d, factor_bps=%d, выплаты=%d, удержано=%d центов.',
            $code,
            $cal->run_version,
            $cal->base_bv_cents,
            $cal->total_after_caps_cents,
            $cal->factor_bps,
            $cal->scaled_total_cents,
            $cal->company_retained_cents,
        ));

        return self::SUCCESS;
    }
}
