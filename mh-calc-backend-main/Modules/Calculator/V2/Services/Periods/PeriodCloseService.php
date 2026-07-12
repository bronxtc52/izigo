<?php

namespace Modules\Calculator\V2\Services\Periods;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Contracts\CalcPeriodService as CalcPeriodServiceContract;
use Modules\Calculator\V2\Contracts\QuarterGlobalPayoutHandler;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Domain\PeriodWindow;

/**
 * V2 T04: оркестратор закрытия периодов (контракт CalcPeriodService). Порядок:
 * claim окна (JobExecutionGuard, идемпотентность DEC-019) → транзакция →
 * pg_advisory_xact_lock(ACTIVATION_LOCK_KEY 0x12916001 — тот же, что
 * ActivationService: сериализация с активациями/пересчётами V1, деньги не
 * задваиваются) → предикаты зависимостей → run (mode=close) → снапшот →
 * PeriodCloseStep-ы по order() (DEC-053; регистрируют T06/T09/T11) →
 * result_hash → период closed.
 *
 * Исключение в шаге откатывает ВСЁ (период остаётся open, постингов нет,
 * частичного closed не существует); failed-run фиксируется пост-фактум для
 * диагностики. Предикаты: месяц — только после закрытия обоих half-month;
 * квартал — только после 3 закрытых месяцев (иначе job FAILED без постинга).
 * Перевод НС→ОС НЕ здесь — job calc-v2:ns-os-transfer (MF-4/MF-6).
 */
class PeriodCloseService implements CalcPeriodServiceContract
{
    public const ENGINE_VERSION = 'v2-t04.1';

    public const JOB_HALF_MONTH_CLOSE = 'half-month-close';
    public const JOB_MONTH_CLOSE = 'month-close';
    public const JOB_QUARTER_PAYOUT = 'quarter-payout';

    public function __construct(
        private readonly PeriodCalendar $calendar,
        private readonly PeriodService $periods,
        private readonly SnapshotService $snapshots,
        private readonly PeriodCloseStepRegistry $registry,
        private readonly JobExecutionGuard $guard,
        private readonly ActivationService $activation,
        private readonly Application $app,
    ) {
    }

    public function closeHalfMonth(string $periodCode): void
    {
        $window = $this->calendar->fromCode($periodCode);
        if ($window->type !== CalcPeriod::TYPE_HALF_MONTH) {
            throw new \InvalidArgumentException("Код {$periodCode} — не half-month период.");
        }

        $this->close($window, self::JOB_HALF_MONTH_CLOSE);
    }

    public function closeMonth(string $month): void
    {
        $window = $this->calendar->fromCode($month);
        if ($window->type !== CalcPeriod::TYPE_MONTH) {
            throw new \InvalidArgumentException("Код {$month} — не month период.");
        }

        $this->close($window, self::JOB_MONTH_CLOSE);
    }

    /** Закрыть квартал + квартальная выплата глобального пула (handler T09, Null до него). */
    public function closeQuarter(string $quarterCode): void
    {
        $window = $this->calendar->fromCode($quarterCode);
        if ($window->type !== CalcPeriod::TYPE_QUARTER) {
            throw new \InvalidArgumentException("Код {$quarterCode} — не quarter период.");
        }

        $this->close($window, self::JOB_QUARTER_PAYOUT);
    }

    /**
     * Диагностический preview-прогон: run (mode=preview) + снапшот + шаги, БЕЗ смены
     * статуса периода и без claim окна. Шаги обязаны уважать $run->mode === preview
     * (не постить деньги). Детерминизм: два preview на идентичных входах дают
     * одинаковый result_hash (ARCH-NFR-01).
     */
    public function runPreview(string $periodCode): CalcRun
    {
        $window = $this->calendar->fromCode($periodCode);

        return DB::transaction(function () use ($window) {
            $period = $this->periods->ensure($window);
            $runNo = $this->nextRunNo($period);
            $run = CalcRun::query()->create([
                'period_id' => $period->id,
                'run_no' => $runNo,
                'mode' => CalcRun::MODE_PREVIEW,
                'status' => CalcRun::STATUS_RUNNING,
                'input_cutoff' => $period->ends_at,
                'engine_version' => self::ENGINE_VERSION,
                'idempotency_key' => sprintf('preview:%s:%s:%d', $period->period_type, $period->code, $runNo),
                'started_at' => now(),
            ]);

            $steps = $this->registry->stepsFor($period->period_type);
            $this->collectStepSections($steps, $period);
            $snapshot = $this->snapshots->freeze($run, $period);
            $metrics = $this->executeSteps($steps, $run, $period);

            $run->update([
                'status' => CalcRun::STATUS_SUCCEEDED,
                'step_results' => $metrics,
                'result_hash' => $this->resultHash($snapshot->payload_hash, $metrics),
                'finished_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    private function close(PeriodWindow $window, string $jobName): void
    {
        $period = $this->periods->ensure($window);

        // Дью-чек ДО claim: незавершённое окно — не failed-исполнение, а «рано»
        // (не плодим failed-ряды на ежедневных тиках текущего периода).
        if (! $window->endedBy(now())) {
            throw new PeriodCloseBlockedException("Период {$window->code} ещё не завершён — закрытие недоступно.");
        }

        // Шорткат «уже закрыт» НАМЕРЕННО после claim: если прошлый прогон упал между
        // commit'ом закрытия и succeed окна, retake протухшего lease дойдёт до
        // транзакции, увидит closed и реконсилирует окно в succeeded.
        $execution = $this->guard->claim($jobName, $window->code);
        if ($execution === null) {
            return; // окно уже succeeded или живой конкурент
        }

        $runStarted = false;

        try {
            DB::transaction(function () use ($period, $jobName, $window, &$runStarted) {
                // Единый порядок локов с V1: advisory-lock ДО любых записей.
                $this->activation->acquireActivationLock();

                $period->refresh();
                if ($period->isClosed()) {
                    return;
                }

                $this->assertDependenciesClosed($period);

                $period->update(['status' => CalcPeriod::STATUS_CLOSING]);

                $run = $this->startCloseRun($period);
                $runStarted = true;

                $steps = $this->registry->stepsFor($period->period_type);
                $this->collectStepSections($steps, $period); // входы шагов — В снапшот, до freeze
                $snapshot = $this->snapshots->freeze($run, $period);
                $metrics = $this->executeSteps($steps, $run, $period);

                if ($jobName === self::JOB_QUARTER_PAYOUT) {
                    $metrics[] = [
                        'step' => 'quarter_global_payout',
                        'metrics' => $this->app
                            ->make(QuarterGlobalPayoutHandler::class)
                            ->payQuarter($period, $this->closedMonthIdsOf($period), $window->code),
                    ];
                }

                $run->update([
                    'status' => CalcRun::STATUS_SUCCEEDED,
                    'step_results' => $metrics,
                    'result_hash' => $this->resultHash($snapshot->payload_hash, $metrics),
                    'finished_at' => now(),
                ]);

                $period->update([
                    'status' => CalcPeriod::STATUS_CLOSED,
                    'closed_at' => now(),
                    'closed_by' => 'system',
                ]);
            });

            $this->guard->succeed($execution);
        } catch (\Throwable $e) {
            if ($runStarted) {
                $this->recordFailedRun($period, $e); // транзакция откатана — фиксируем след для диагностики
            }
            $this->guard->fail($execution, $e->getMessage());
            throw $e;
        }
    }

    /** Предикаты зависимостей (проверяются ПОД локом — статусы не уедут). */
    private function assertDependenciesClosed(CalcPeriod $period): void
    {
        if ($period->period_type === CalcPeriod::TYPE_MONTH) {
            $halves = $this->calendar->halfCodesOfMonth($period->code);
            $closed = CalcPeriod::query()
                ->where('period_type', CalcPeriod::TYPE_HALF_MONTH)
                ->whereIn('code', $halves)
                ->where('status', CalcPeriod::STATUS_CLOSED)
                ->count();
            if ($closed !== 2) {
                throw new PeriodCloseBlockedException(
                    "BLOCKED: месяц {$period->code} закрывается только после закрытия обоих half-month (закрыто {$closed}/2)."
                );
            }
        }

        if ($period->period_type === CalcPeriod::TYPE_QUARTER) {
            $months = $this->calendar->monthCodesOfQuarter($period->code);
            $closed = CalcPeriod::query()
                ->where('period_type', CalcPeriod::TYPE_MONTH)
                ->whereIn('code', $months)
                ->where('status', CalcPeriod::STATUS_CLOSED)
                ->count();
            if ($closed !== 3) {
                throw new PeriodCloseBlockedException(
                    "BLOCKED: квартал {$period->code} закрывается только после закрытия 3 месяцев (закрыто {$closed}/3)."
                );
            }
        }
    }

    /** @return int[] id трёх закрытых месячных периодов квартала */
    private function closedMonthIdsOf(CalcPeriod $quarter): array
    {
        return CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->whereIn('code', $this->calendar->monthCodesOfQuarter($quarter->code))
            ->where('status', CalcPeriod::STATUS_CLOSED)
            ->orderBy('starts_at')
            ->pluck('id')
            ->all();
    }

    /**
     * Pre-freeze хук: секции входов шагов (SnapshotSectionProvider, опционально)
     * попадают в immutable-снапшот ДО исполнения шагов.
     */
    private function collectStepSections(array $steps, CalcPeriod $period): void
    {
        $this->snapshots->reset(); // упавший прошлый прогон не должен протечь секциями
        foreach ($steps as $step) {
            if ($step instanceof \Modules\Calculator\V2\Contracts\SnapshotSectionProvider) {
                foreach ($step->sections($period) as $name => $data) {
                    $this->snapshots->addSection($name, $data);
                }
            }
        }
    }

    /** Список (не карта) — сохраняет порядок каскада и не схлопывает одноимённые классы. */
    private function executeSteps(array $steps, CalcRun $run, CalcPeriod $period): array
    {
        $metrics = [];
        foreach ($steps as $step) {
            $metrics[] = [
                'step' => get_class($step),
                'order' => $step->order(),
                'metrics' => $step->execute($run, $period),
            ];
        }

        return $metrics;
    }

    /**
     * Боевой close-run: idempotency_key 'close:{type}:{code}' UNIQUE. Failed-run
     * прошлой попытки (записанный recordFailedRun) переиспользуется тем же рядом.
     */
    private function startCloseRun(CalcPeriod $period): CalcRun
    {
        $key = sprintf('close:%s:%s', $period->period_type, $period->code);

        $existing = CalcRun::query()->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if ($existing->status === CalcRun::STATUS_SUCCEEDED) {
                throw new \LogicException("Run {$key} уже succeeded, повторное закрытие невозможно.");
            }
            $existing->update([
                'status' => CalcRun::STATUS_RUNNING,
                'error' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            return $existing->refresh();
        }

        return CalcRun::query()->create([
            'period_id' => $period->id,
            'run_no' => $this->nextRunNo($period),
            'mode' => CalcRun::MODE_CLOSE,
            'status' => CalcRun::STATUS_RUNNING,
            'input_cutoff' => $period->ends_at,
            'engine_version' => self::ENGINE_VERSION,
            'idempotency_key' => $key,
            'started_at' => now(),
        ]);
    }

    /** Пост-фактум след упавшего закрытия (сама транзакция откатана целиком). */
    private function recordFailedRun(CalcPeriod $period, \Throwable $e): void
    {
        try {
            $key = sprintf('close:%s:%s', $period->period_type, $period->code);
            $error = mb_substr($e->getMessage(), 0, 2000);

            $existing = CalcRun::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                $existing->update([
                    'status' => CalcRun::STATUS_FAILED,
                    'error' => $error,
                    'finished_at' => now(),
                ]);

                return;
            }

            CalcRun::query()->create([
                'period_id' => $period->id,
                'run_no' => $this->nextRunNo($period),
                'mode' => CalcRun::MODE_CLOSE,
                'status' => CalcRun::STATUS_FAILED,
                'input_cutoff' => $period->ends_at,
                'engine_version' => self::ENGINE_VERSION,
                'idempotency_key' => $key,
                'error' => $error,
                'started_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (\Throwable) {
            // best-effort: диагностический след не должен маскировать исходную ошибку
        }
    }

    private function nextRunNo(CalcPeriod $period): int
    {
        return (int) CalcRun::query()->where('period_id', $period->id)->max('run_no') + 1;
    }

    /** Детерминированный hash результата: снапшот входов + канонические метрики шагов. */
    private function resultHash(string $payloadHash, array $metrics): string
    {
        return hash('sha256', $payloadHash . SnapshotService::hash(['steps' => $metrics]));
    }
}
