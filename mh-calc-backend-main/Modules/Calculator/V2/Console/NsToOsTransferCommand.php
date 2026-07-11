<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\NsToOsTransfer;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\JobExecutionGuard;

/**
 * V2 T04: перевод НС→ОС — ЕДИНСТВЕННЫЙ владелец команды и расписания (MF-6).
 * Семантика MF-4 (вариант A): структурная премия остаётся на НС до закрытия
 * МЕСЯЦА и коммита 60%-калибровки; перевод выполняется за оба полумесяца
 * прошедшего месяца, уже умноженный на factor_bps. Провизорных переводов 16-го
 * и clawback'ов НЕТ.
 *
 * Гейт fail-closed: месяц closed И PoolCalibrationReader вернул factor_bps
 * (до merge T11 — Null-reader, переводов нет). Операция — NsToOsTransfer
 * (реализация T02, до неё Null). Идемпотентность: окно 'ns-os:YYYY-MM' в
 * v2_calc_job_executions — handler вызывается ровно один раз на месяц.
 */
class NsToOsTransferCommand extends Command
{
    protected $signature = 'calc-v2:ns-os-transfer';

    protected $description = 'V2: перевод НС→ОС за откалиброванные закрытые месяцы (идемпотентно по месяцу)';

    public function handle(
        FeatureFlagService $flags,
        PoolCalibrationReader $calibrations,
        NsToOsTransfer $transfer,
        JobExecutionGuard $guard,
        ActivationService $activation,
    ): int {
        if (! $flags->isEnabled('mh_plan_v2_periods')) {
            return self::SUCCESS;
        }

        $months = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->where('status', CalcPeriod::STATUS_CLOSED)
            ->orderBy('starts_at')
            ->get();

        $failures = 0;
        foreach ($months as $month) {
            $factorBps = $calibrations->factorBpsFor($month->code);
            if ($factorBps === null) {
                continue; // месяц ещё не откалиброван (T11) — переводить рано, ждём
            }

            $execution = $guard->claim('ns-os-transfer', 'ns-os:' . $month->code);
            if ($execution === null) {
                continue; // окно уже succeeded или живой конкурент
            }

            try {
                DB::transaction(function () use ($transfer, $activation, $month, $factorBps) {
                    // Единый порядок локов с V1/закрытиями: сериализация ledger-проводок.
                    $activation->acquireActivationLock();
                    $transfer->executeForCalibratedMonth($month->code, $factorBps);
                });
                $guard->succeed($execution);
                $this->info("НС→ОС за {$month->code} выполнен (factor_bps={$factorBps}).");
            } catch (\Throwable $e) {
                $guard->fail($execution, $e->getMessage());
                $failures++;
                $this->error("НС→ОС за {$month->code} упал: {$e->getMessage()}");
                report($e);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
