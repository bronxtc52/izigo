<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Services\AdminService;

/**
 * Админ-портал: участники, роли, настройка плана. Доступ ограничен RBAC-гейтами
 * на маршрутах (calculator.role). Лидер видит только своё поддерево.
 *
 * @group Admin
 */
class AdminController
{
    public function __construct(private readonly AdminService $service)
    {
    }

    public function members(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->listMembers(
            CalculatorAuth::token()->user,
            $request->only(['search', 'status', 'rank_id', 'per_page']),
        ));
    }

    public function member(int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->service->getMember(CalculatorAuth::token()->user, $id));
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role' => 'required|in:owner,finance,leader,support',
            // Лидеру охват обязателен (иначе он молча «видит всех»).
            'leader_scope_member_id' => 'required_if:role,leader|nullable|integer|exists:members,id',
        ]);

        return $this->guarded(fn () => $this->service->assignRole(
            $id,
            $data['role'],
            $data['leader_scope_member_id'] ?? null,
        ));
    }

    public function revokeRole(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['role' => 'required|in:owner,finance,leader,support']);

        return $this->guarded(fn () => $this->service->revokeRole($id, $data['role']));
    }

    public function planSettings(): JsonResponse
    {
        return $this->guarded(fn () => $this->service->getPlanSettings());
    }

    public function updatePlanSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'placement_mode' => 'nullable|in:auto,manual',
            // Бонусы рангов идут в расчёт — только неотрицательные числа.
            'rank_bonuses' => 'nullable|array',
            'rank_bonuses.*' => 'numeric|min:0',
        ]);

        return $this->guarded(fn () => $this->service->updatePlanSettings($data));
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
