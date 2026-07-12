<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Services\Awards\Exceptions\AwardConflictException;
use Modules\Calculator\V2\Services\Awards\Exceptions\AwardNotFoundException;
use Modules\Calculator\V2\Services\Awards\QualificationAwardService;

/**
 * T10: API квалификационных наград.
 *  - cabinet (Mini App, telegram.auth + feature.flag:mh_v2_awards): участник видит
 *    ТОЛЬКО СВОИ награды (member из auth-атрибута, id клиента не принимается — IDOR);
 *  - admin (веб-админка, web.admin + feature.flag:mh_v2_awards): очередь наград +
 *    ручной payout-контур. RBAC на роутах: read — owner,finance; mutation
 *    (mark-paid/hold/release/forfeit) — owner-only (amendments NTH-1). Данные —
 *    контракт страницы «очередь наград» T13.
 */
class AwardsController
{
    private const QUEUE_LIMIT = 200;

    public function __construct(
        private readonly QualificationAwardService $awards,
    ) {
    }

    // ------------------------------------------------------------------
    // Cabinet (Mini App)
    // ------------------------------------------------------------------

    /** Мои награды (все статусы), новые сверху. */
    public function me(Request $request): JsonResponse
    {
        $member = $this->authMember($request);

        $rows = AwardEntitlement::query()
            ->where('member_id', $member->id)
            ->orderByDesc('granted_at')
            ->get(['id', 'award_code', 'stage_no', 'amount_cents', 'status', 'granted_at', 'paid_at']);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    // ------------------------------------------------------------------
    // Admin (веб-админка)
    // ------------------------------------------------------------------

    /** Очередь наград, фильтр ?status=granted|on_hold|paid_out|forfeited. */
    public function queue(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $query = AwardEntitlement::query()->orderByDesc('granted_at')->limit(self::QUEUE_LIMIT);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json(['status' => 'success', 'data' => $query->get([
            'id', 'member_id', 'award_code', 'stage_no', 'amount_cents', 'status',
            'trigger_type', 'trigger_ref', 'granted_at', 'posted_at', 'paid_at',
            'paid_by_admin_id', 'note',
        ])]);
    }

    public function markPaid(Request $request, int $id): JsonResponse
    {
        return $this->action(
            fn (int $adminId) => $this->awards->markPaid($id, $adminId, $this->note($request)),
            $request,
        );
    }

    public function hold(Request $request, int $id): JsonResponse
    {
        return $this->action(
            fn (int $adminId) => $this->awards->hold($id, $adminId, $this->note($request)),
            $request,
        );
    }

    public function release(Request $request, int $id): JsonResponse
    {
        return $this->action(
            fn (int $adminId) => $this->awards->release($id, $adminId, $this->note($request)),
            $request,
        );
    }

    public function forfeit(Request $request, int $id): JsonResponse
    {
        $reason = $this->note($request);
        if ($reason === null || trim($reason) === '') {
            return response()->json(['status' => 'error', 'message' => 'forfeit требует reason'], 422);
        }

        return $this->action(
            fn (int $adminId) => $this->awards->forfeit($id, $adminId, $reason),
            $request,
        );
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    /** @param callable(int):AwardEntitlement $op */
    private function action(callable $op, Request $request): JsonResponse
    {
        $adminId = $request->attributes->get('member')?->id;
        try {
            $entitlement = $op((int) $adminId);
        } catch (AwardNotFoundException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 404);
        } catch (AwardConflictException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        }

        return response()->json(['status' => 'success', 'data' => $entitlement->only([
            'id', 'member_id', 'award_code', 'stage_no', 'amount_cents', 'status',
            'granted_at', 'posted_at', 'paid_at', 'paid_by_admin_id', 'note',
        ])]);
    }

    private function note(Request $request): ?string
    {
        $note = $request->input('note') ?? $request->input('reason');

        return is_string($note) && $note !== '' ? $note : null;
    }

    private function authMember(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        abort_if($member === null, 403, 'Требуется активированный участник');

        return $member;
    }
}
