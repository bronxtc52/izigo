<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Services\AdminReportService;

/**
 * Read-эндпоинты разделов веб-админки: Дашборд (KPI), Финансы (ledger + кошелёк),
 * Операции (платежи, autoship). RBAC-гейты — на маршрутах (calculator.role).
 *
 * @group Admin
 */
class AdminReportController
{
    public function __construct(private readonly AdminReportService $reports)
    {
    }

    public function dashboard(): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->dashboard());
    }

    public function ledger(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->ledger(
            $request->only(['member_id', 'account_type', 'source_type', 'per_page']),
        ));
    }

    public function memberWallet(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->memberWallet($id));
    }

    public function payments(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->payments(
            $request->only(['status', 'purpose', 'per_page']),
        ));
    }

    public function autoship(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->autoship($request->only(['status', 'per_page'])));
    }

    public function reportBalances(): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->reportBalances());
    }

    public function reportUsers(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->reportUsers($request->only(['status', 'from', 'to'])));
    }

    public function reportSales(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->reportSales($request->only(['from', 'to'])));
    }

    public function reportBonusExpense(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->reports->reportBonusExpense($request->only(['from', 'to'])));
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        }
    }
}
