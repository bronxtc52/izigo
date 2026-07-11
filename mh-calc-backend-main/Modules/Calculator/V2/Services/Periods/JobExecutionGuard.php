<?php

namespace Modules\Calculator\V2\Services\Periods;

use Illuminate\Database\QueryException;
use Modules\Calculator\V2\Domain\CalcJobExecution;

/**
 * V2 T04: идемпотентность scheduled-джобов по окну (DEC-019) поверх
 * v2_calc_job_executions UNIQUE(job_name, window_key):
 *  - succeeded-окно → null (no-op, деньги не задваиваются);
 *  - running с живым lease → null (конкурент работает);
 *  - running с протухшим lease / failed → перехват тем же рядом (attempts+1);
 *  - конкурентная вставка одного окна → unique violation → корректный null,
 *    не exception наружу.
 */
class JobExecutionGuard
{
    /** Lease running-исполнения, минут: дольше — считаем упавшим и перехватываем. */
    public const LEASE_MINUTES = 60;

    /** Занять окно. null = исполнять нельзя (успело другое исполнение / уже сделано). */
    public function claim(string $jobName, string $windowKey): ?CalcJobExecution
    {
        $existing = CalcJobExecution::query()
            ->where('job_name', $jobName)
            ->where('window_key', $windowKey)
            ->first();

        if ($existing !== null) {
            return $this->retake($existing);
        }

        try {
            return CalcJobExecution::query()->create([
                'job_name' => $jobName,
                'window_key' => $windowKey,
                'status' => CalcJobExecution::STATUS_RUNNING,
                'attempts' => 1,
                'started_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null; // конкурент вставил окно первым — корректный no-op
            }
            throw $e;
        }
    }

    public function succeed(CalcJobExecution $execution): void
    {
        $execution->update([
            'status' => CalcJobExecution::STATUS_SUCCEEDED,
            'finished_at' => now(),
            'error' => null,
        ]);
    }

    public function fail(CalcJobExecution $execution, string $error): void
    {
        $execution->update([
            'status' => CalcJobExecution::STATUS_FAILED,
            'finished_at' => now(),
            'error' => mb_substr($error, 0, 2000),
        ]);
    }

    private function retake(CalcJobExecution $existing): ?CalcJobExecution
    {
        if ($existing->status === CalcJobExecution::STATUS_SUCCEEDED) {
            return null;
        }

        if ($existing->status === CalcJobExecution::STATUS_RUNNING
            && $existing->started_at !== null
            && $existing->started_at->gt(now()->subMinutes(self::LEASE_MINUTES))) {
            return null; // живой конкурент
        }

        // failed или протухший running: атомарный перехват тем же рядом. Условие в
        // WHERE защищает от гонки двух перехватчиков (обновится ровно у одного).
        $taken = CalcJobExecution::query()
            ->whereKey($existing->id)
            ->where('status', $existing->status)
            ->where('updated_at', $existing->updated_at)
            ->update([
                'status' => CalcJobExecution::STATUS_RUNNING,
                'attempts' => $existing->attempts + 1,
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
                'updated_at' => now(),
            ]);

        return $taken === 1 ? $existing->refresh() : null;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23505';
    }
}
