<?php

namespace Modules\Calculator\V2\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Bonus\LeadershipBonusService;

/**
 * T08 — ручной прогон/backfill лидерского бонуса периода:
 * calculator:v2:leadership-run {period}. НЕ команда закрытия периода (те — у T04, MF-6):
 * штатно лидерский считается шагом MONTH-close (LeadershipCloseStep) ПОСЛЕ калибровки T11.
 * Эта команда — ручной пересчёт по коду периода (месяц 'YYYY-MM' штатно; половина месяца —
 * диагностика с некалиброванной базой). Идемпотентна; флаг OFF → no-op; закрытый период
 * → отказ (только корректирующие проводки T12). withoutOverlapping — в расписании (тут нет).
 */
class LeadershipRunCommand extends Command
{
    public const FLAG = 'mh_v2_leadership';

    protected $signature = 'calculator:v2:leadership-run {period : Код периода (месяц YYYY-MM или half-month, напр. 2026-03-H2)}';

    protected $description = 'T08: (пере)прогон лидерского бонуса периода (начисление на ОС)';

    public function handle(LeadershipBonusService $service, FeatureFlagService $flags): int
    {
        if (! $flags->isEnabled(self::FLAG)) {
            $this->warn('Флаг mh_v2_leadership выключен — no-op.');

            return self::SUCCESS;
        }

        $code = (string) $this->argument('period');
        $period = CalcPeriod::query()->where('code', $code)->first();
        if ($period === null) {
            $this->error("Период {$code} не найден (создаётся джобом периодов T04).");

            return self::FAILURE;
        }

        $metrics = $service->runForPeriod($period);
        $this->info(sprintf(
            'Лидерский %s (%s): источников=%d, начислений=%d, исключений=%d, выплачено=%d центов [%s].',
            $code,
            $period->period_type,
            $metrics['sources'],
            $metrics['posted'],
            $metrics['excluded'],
            $metrics['posted_cents'],
            CarbonImmutable::now('UTC')->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
