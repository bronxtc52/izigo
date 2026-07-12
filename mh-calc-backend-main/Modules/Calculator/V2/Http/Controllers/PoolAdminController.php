<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\PoolCalibrationItem;
use Modules\Calculator\V2\Services\Pool\PoolCalibrationService;
use Modules\Calculator\V2\Services\Pool\PoolReportService;

/**
 * T11: admin-отчёт 60%-калибровки + preview-пересчёт. RBAC на маршрутах (v2_pool.php):
 * read — calculator.role:owner,finance; recalibrate (mutation) — calculator.role:owner;
 * группа под web.admin + feature.flag:mh_v2_pool (deny-by-default). recalibrate на CLOSED
 * периоде → 422 (корректировки закрытого периода — контур T12, не T11).
 */
class PoolAdminController
{
    public function __construct(
        private readonly PoolReportService $report,
        private readonly PoolCalibrationService $service,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Список committed-калибровок (новые сверху). */
    public function periods(): JsonResponse
    {
        return response()->json(['status' => 'success', 'data' => $this->report->list()]);
    }

    /** Отчёт месяца: заголовок + разбивка по видам бонусов. */
    public function period(string $code): JsonResponse
    {
        $cal = $this->report->findCommitted($code);
        if ($cal === null) {
            return response()->json(['status' => 'error', 'message' => "Месяц {$code} не откалиброван"], 404);
        }

        return response()->json(['status' => 'success', 'data' => $this->report->report($cal)]);
    }

    /** Постраничный per-member drill-down. */
    public function members(string $code): JsonResponse
    {
        $cal = $this->report->findCommitted($code);
        if ($cal === null) {
            return response()->json(['status' => 'error', 'message' => "Месяц {$code} не откалиброван"], 404);
        }

        $page = $this->report->membersPage($cal);

        return response()->json([
            'status' => 'success',
            'data' => $page->getCollection()->map(fn (PoolCalibrationItem $i) => [
                'member_id' => $i->member_id,
                'bonus_kind' => $i->bonus_kind,
                'source_ref' => $i->source_ref,
                'amount_after_caps_cents' => (int) $i->amount_after_caps_cents,
                'calibrated_cents' => (int) $i->calibrated_cents,
                'retained_cents' => (int) $i->retained_cents,
                'state' => $i->state,
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Пересчитать/закоммитить калибровку месяца (owner-only, аудит). Только для
     * НЕ закрытого месяца: на CLOSED → 422 (закрытый период правит только T12).
     * Идемпотентно относительно данных; supersede прежней версии (BR-POOL-002).
     */
    public function recalibrate(Request $request, string $code): JsonResponse
    {
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->where('code', $code)
            ->first();
        if ($period === null) {
            return response()->json(['status' => 'error', 'message' => "Месячный период {$code} не найден"], 404);
        }
        if ($period->isClosed()) {
            return response()->json([
                'status' => 'error',
                'message' => "Месяц {$code} закрыт — рекалибровка недоступна (корректировки — контур возвратов T12)",
            ], 422);
        }

        $viewer = $this->viewer($request);
        $cal = $this->service->calibrateMonth($period, $viewer?->id !== null ? "owner:{$viewer->id}" : 'owner');

        $this->audit->recordSafe(
            $viewer?->id,
            'v2.pool.recalibrate',
            'v2_pool_calibration',
            $cal->id,
            null,
            ['month' => $code, 'run_version' => $cal->run_version, 'factor_bps' => $cal->factor_bps],
        );

        return response()->json(['status' => 'success', 'data' => $this->report->report($cal)]);
    }

    private function viewer(Request $request): ?Member
    {
        return $request->attributes->get('member');
    }
}
