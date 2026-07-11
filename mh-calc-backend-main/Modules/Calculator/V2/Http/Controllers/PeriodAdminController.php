<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Periods\PeriodCloseBlockedException;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;

/**
 * V2 T04: admin-чтение расчётных периодов + ручной идемпотентный триггер закрытия.
 * RBAC на маршрутах (v2_periods.php): read — calculator.role:owner,finance,
 * mutation (close) — calculator.role:owner; группа под web.admin +
 * feature.flag:mh_plan_v2_admin (deny-by-default, каркас W0).
 * Формат ответа — контракт чтения для админки T13 (заморожен Гейтом A).
 */
class PeriodAdminController
{
    public function __construct(
        private readonly PeriodCloseService $closer,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Список периодов: фильтры ?type=half_month|month|quarter, ?status=open|closing|closed. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'sometimes|string|in:half_month,month,quarter',
            'status' => 'sometimes|string|in:open,closing,closed',
        ]);

        $periods = CalcPeriod::query()
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('period_type', $type))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->withCount('runs')
            ->orderByDesc('starts_at')
            ->orderBy('period_type')
            ->limit(200)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $periods->map(fn (CalcPeriod $p) => $this->presentPeriod($p))->all(),
        ]);
    }

    /** Период + его прогоны (step_results, мета снапшота — payload целиком не отдаём). */
    public function show(int $id): JsonResponse
    {
        $period = CalcPeriod::query()->with(['runs.snapshot'])->find($id);
        if ($period === null) {
            return response()->json(['status' => 'error', 'message' => 'Период не найден'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->presentPeriod($period) + [
                'runs' => $period->runs->map(fn (CalcRun $run) => [
                    'id' => $run->id,
                    'run_no' => $run->run_no,
                    'mode' => $run->mode,
                    'status' => $run->status,
                    'input_cutoff' => $run->input_cutoff?->toIso8601ZuluString(),
                    'engine_version' => $run->engine_version,
                    'result_hash' => $run->result_hash,
                    'idempotency_key' => $run->idempotency_key,
                    'step_results' => $run->step_results,
                    'error' => $run->error,
                    'started_at' => $run->started_at?->toIso8601ZuluString(),
                    'finished_at' => $run->finished_at?->toIso8601ZuluString(),
                    'snapshot' => $run->snapshot === null ? null : [
                        'id' => $run->snapshot->id,
                        'payload_hash' => $run->snapshot->payload_hash,
                        'created_at' => $run->snapshot->created_at?->toIso8601ZuluString(),
                    ],
                ])->all(),
            ],
        ]);
    }

    /**
     * Ручной идемпотентный триггер закрытия (owner-only): повтор по закрытому
     * периоду — no-op 200 (тот же run). BLOCKED-предикаты → 409, период не истёк → 422.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $period = CalcPeriod::query()->find($id);
        if ($period === null) {
            return response()->json(['status' => 'error', 'message' => 'Период не найден'], 404);
        }

        try {
            match ($period->period_type) {
                CalcPeriod::TYPE_HALF_MONTH => $this->closer->closeHalfMonth($period->code),
                CalcPeriod::TYPE_MONTH => $this->closer->closeMonth($period->code),
                CalcPeriod::TYPE_QUARTER => $this->closer->closeQuarter($period->code),
                default => throw new \InvalidArgumentException("Неизвестный тип периода {$period->period_type}"),
            };
        } catch (PeriodCloseBlockedException $e) {
            $code = str_contains($e->getMessage(), 'не завершён') ? 422 : 409;

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], $code);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        $this->audit->recordSafe(
            $this->viewer($request)->id,
            'v2_period.close',
            'v2_calc_period',
            $period->id,
            null,
            ['code' => $period->code],
        );

        return response()->json([
            'status' => 'success',
            'data' => $this->presentPeriod($period->refresh()->loadCount('runs')),
        ]);
    }

    private function presentPeriod(CalcPeriod $period): array
    {
        return [
            'id' => $period->id,
            'period_type' => $period->period_type,
            'code' => $period->code,
            'starts_at' => $period->starts_at->toIso8601ZuluString(),
            'ends_at' => $period->ends_at->toIso8601ZuluString(),
            'timezone' => $period->timezone,
            'status' => $period->status,
            'policy_version_id' => $period->policy_version_id,
            'closed_at' => $period->closed_at?->toIso8601ZuluString(),
            'closed_by' => $period->closed_by,
            'runs_count' => (int) ($period->runs_count ?? 0),
        ];
    }

    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }
}
