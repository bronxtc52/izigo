<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AdminService;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\PlanSettingsService;
use Modules\Calculator\Services\WithdrawalService;
use RuntimeException;

/**
 * Админ-портал: участники, роли, настройка плана. Доступ ограничен RBAC-гейтами
 * на маршрутах (calculator.role). Лидер видит только своё поддерево.
 *
 * @group Admin
 */
class AdminController
{
    public function __construct(
        private readonly AdminService $service,
        private readonly WithdrawalService $withdrawals,
        private readonly PlanSettingsService $planSettings,
        private readonly AuditLogService $audit,
    ) {
    }

    public function members(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->service->listMembers(
            $this->viewer($request),
            $request->only(['search', 'status', 'rank_id', 'per_page']),
        ));
    }

    public function member(Request $request, int $id): JsonResponse
    {
        return $this->guarded(fn () => $this->service->getMember($this->viewer($request), $id));
    }

    public function assignRole(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'role' => 'required|in:owner,finance,leader,support',
            // Лидеру охват обязателен (иначе он молча «видит всех»).
            'leader_scope_member_id' => 'required_if:role,leader|nullable|integer|exists:members,id',
        ]);

        return $this->guarded(fn () => DB::transaction(function () use ($request, $id, $data) {
            $result = $this->service->assignRole($id, $data['role'], $data['leader_scope_member_id'] ?? null);
            $this->audit->record($this->viewer($request)->id, 'role.assign', 'member', $id, null, [
                'role' => $data['role'],
                'leader_scope_member_id' => $data['leader_scope_member_id'] ?? null,
                'roles' => $result['roles'],
            ]);

            return $result;
        }));
    }

    public function revokeRole(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['role' => 'required|in:owner,finance,leader,support']);

        return $this->guarded(fn () => DB::transaction(function () use ($request, $id, $data) {
            $result = $this->service->revokeRole($id, $data['role']);
            $this->audit->record($this->viewer($request)->id, 'role.revoke', 'member', $id, null, [
                'role' => $data['role'],
                'roles' => $result['roles'],
            ]);

            return $result;
        }));
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

    // --- Маркетинг-план (полный документ): боевые проценты/ранги/пакеты ---

    /** Полный текущий документ плана (дефолты + оверрайды) для редактирования. */
    public function plan(): JsonResponse
    {
        return $this->guarded(fn () => $this->planSettings->current());
    }

    /** Заменить документ плана (только owner). Forward-only + аудит. */
    public function updatePlan(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->planSettings->update($request->all(), $this->viewer($request)->id));
    }

    /** Лента аудита админ-действий (только owner). */
    public function auditLog(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->audit->list($request->only(['action', 'entity_type', 'per_page'])));
    }

    // --- Заявки на вывод (финансист): очередь + статус-машина ---

    public function withdrawals(Request $request): JsonResponse
    {
        return $this->guarded(fn () => $this->withdrawals->listForAdmin($request->query('status')));
    }

    public function approveWithdrawal(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $result = $this->withdrawals->approve($id, $this->viewer($request));
            $this->audit->recordSafe($this->viewer($request)->id, 'withdrawal.approve', 'withdrawal', $id, null, null);

            return $result;
        });
    }

    public function rejectWithdrawal(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return $this->guarded(function () use ($request, $id, $data) {
            $result = $this->withdrawals->reject($id, $this->viewer($request), $data['reason']);
            $this->audit->recordSafe($this->viewer($request)->id, 'withdrawal.reject', 'withdrawal', $id, null, ['reason' => $data['reason']]);

            return $result;
        });
    }

    public function markPaidWithdrawal(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $result = $this->withdrawals->markPaid($id);
            $this->audit->recordSafe($this->viewer($request)->id, 'withdrawal.mark_paid', 'withdrawal', $id, null, null);

            return $result;
        });
    }

    /** approved → paid через on-chain выплату USDT (Фаза 4, S7). */
    public function sendWithdrawal(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $result = $this->withdrawals->sendOnChain($id);
            $this->audit->recordSafe($this->viewer($request)->id, 'withdrawal.send', 'withdrawal', $id, null, null);

            return $result;
        });
    }

    public function cancelWithdrawal(Request $request, int $id): JsonResponse
    {
        return $this->guarded(function () use ($request, $id) {
            $result = $this->withdrawals->cancel($id, $this->viewer($request));
            $this->audit->recordSafe($this->viewer($request)->id, 'withdrawal.cancel', 'withdrawal', $id, null, null);

            return $result;
        });
    }

    /** Текущий участник-наблюдатель, резолвленный telegram.auth. */
    private function viewer(Request $request): Member
    {
        return $request->attributes->get('member');
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }
}
