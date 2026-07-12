<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\PeriodCorrection;
use Modules\Calculator\V2\Services\Refunds\Exceptions\RefundConflictException;
use Modules\Calculator\V2\Services\Refunds\Exceptions\RefundValidationException;
use Modules\Calculator\V2\Services\Refunds\PeriodCorrectionService;
use Modules\Calculator\V2\Services\Refunds\RefundService;

/**
 * T12: админ-API возвратов/сторно (веб-админка, web.admin + feature.flag:mh_v2_refunds).
 * RBAC на роутах (amendments NTH-1, роль в middleware): read (список/деталь возврата,
 * очередь корректировок) — owner,finance; mutation (create возврата, approve/reject/post
 * корректировки) — owner-only. Возврат средств покупателю — ВНЕ системы (не здесь).
 */
class RefundAdminController
{
    private const LIST_LIMIT = 200;

    public function __construct(
        private readonly RefundService $refunds,
        private readonly PeriodCorrectionService $corrections,
    ) {
    }

    // ------------------------------------------------------------------
    // Возвраты
    // ------------------------------------------------------------------

    /** Создать возврат (owner). full → все позиции; partial → lines[{order_item_id,qty}]. */
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'kind' => ['required', 'in:full,partial'],
            'reason' => ['required', 'string', 'max:1000'],
            'lines' => ['array'],
            'lines.*.order_item_id' => ['required_with:lines', 'integer'],
            'lines.*.qty' => ['required_with:lines', 'integer', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $adminId = optional($request->get('member'))->id;
        $key = $data['idempotency_key'] ?? ('v2:return:' . (string) Str::uuid());

        try {
            $return = $this->refunds->create(
                orderId: (int) $data['order_id'],
                kind: $data['kind'],
                requestedLines: $data['lines'] ?? [],
                reason: $data['reason'],
                adminId: $adminId,
                idempotencyKey: $key,
            );
        } catch (RefundValidationException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'success', 'data' => $this->presentReturn($return)], 201);
    }

    /** Список возвратов (owner,finance), фильтр ?status=. */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = OrderReturn::query()->with(['lines', 'corrections'])
            ->orderByDesc('id')->limit(self::LIST_LIMIT);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->get()->map(fn (OrderReturn $r) => $this->presentReturn($r)),
        ]);
    }

    /** Деталь возврата с reversal-chain (owner,finance). */
    public function show(int $id): JsonResponse
    {
        $return = OrderReturn::query()->with(['lines', 'actions', 'corrections'])->find($id);
        if ($return === null) {
            return response()->json(['status' => 'error', 'message' => 'Возврат не найден'], 404);
        }

        return response()->json(['status' => 'success', 'data' => $this->presentReturn($return, withChain: true)]);
    }

    // ------------------------------------------------------------------
    // Корректировки закрытых периодов
    // ------------------------------------------------------------------

    /** Очередь корректировок (owner,finance), фильтр ?status=. */
    public function corrections(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = PeriodCorrection::query()->orderByDesc('id')->limit(self::LIST_LIMIT);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json(['status' => 'success', 'data' => $query->get()]);
    }

    /** Утвердить корректировку (owner). */
    public function approveCorrection(Request $request, int $id): JsonResponse
    {
        return $this->correctionAction(fn () => $this->corrections->approve($id, optional($request->get('member'))->id));
    }

    /** Отклонить корректировку (owner). */
    public function rejectCorrection(Request $request, int $id): JsonResponse
    {
        return $this->correctionAction(fn () => $this->corrections->reject($id, optional($request->get('member'))->id));
    }

    /** Провести утверждённую корректировку (owner). */
    public function postCorrection(int $id): JsonResponse
    {
        return $this->correctionAction(fn () => $this->corrections->post($id));
    }

    private function correctionAction(callable $fn): JsonResponse
    {
        try {
            $correction = $fn();
        } catch (RefundConflictException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'success', 'data' => $correction]);
    }

    /** @return array<string,mixed> */
    private function presentReturn(OrderReturn $return, bool $withChain = false): array
    {
        $data = [
            'id' => $return->id,
            'order_id' => $return->order_id,
            'member_id' => $return->member_id,
            'kind' => $return->kind,
            'status' => $return->status,
            'reason' => $return->reason,
            'returned_bv_cents' => $return->returned_bv_cents,
            'returned_pv' => $return->returned_pv,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'lines' => $return->lines,
            'corrections' => $return->corrections,
        ];
        if ($withChain) {
            $data['actions'] = $return->actions;
        }

        return $data;
    }
}
