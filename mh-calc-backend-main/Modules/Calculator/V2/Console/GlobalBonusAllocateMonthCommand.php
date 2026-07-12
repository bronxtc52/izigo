<?php

namespace Modules\Calculator\V2\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService;

/**
 * T09 — доменная команда (пере)расчёта draft-месяца глобального бонуса:
 * calculator:v2:global-allocate {month?}. НЕ команда закрытия периода (те — у T04,
 * MF-6): автоматическая аллокация делается шагом month-close, эта команда — ручной
 * пересчёт draft-месяца админом. Финальный месяц → no-op. Флаг OFF → no-op.
 * withoutOverlapping навешивается в расписании (тут не расписывается — T04 владеет
 * расписанием периодов; команда ручная).
 */
class GlobalBonusAllocateMonthCommand extends Command
{
    public const FLAG = 'mh_v2_global_bonus';

    protected $signature = 'calculator:v2:global-allocate {month? : Код месяца YYYY-MM (по умолчанию прошлый месяц UTC)}';

    protected $description = 'T09: (пере)расчёт draft-месяца глобального бонуса (пулы/доли/аллокации)';

    public function handle(GlobalBonusMonthlyService $service, FeatureFlagService $flags): int
    {
        if (! $flags->isEnabled(self::FLAG)) {
            $this->warn('Флаг mh_v2_global_bonus выключен — no-op.');

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

        $month = $service->allocateForMonth($period);
        $this->info(sprintf(
            'Глобальный бонус %s: status=%s, global_bv=%d центов, пулов=%d.',
            $code,
            $month->status,
            $month->global_bv_cents,
            $month->pools()->count(),
        ));

        return self::SUCCESS;
    }
}
