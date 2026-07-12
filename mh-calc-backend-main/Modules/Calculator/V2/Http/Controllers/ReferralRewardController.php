<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Models\ReferralReward;

/**
 * T07: read-API реферальных премий.
 *  - cabinet (Mini App, middleware telegram.auth + feature.flag:mh_v2_referral):
 *    участник видит ТОЛЬКО СВОИ полученные премии (beneficiary = auth-member;
 *    chosen id игнорируется — структурная защита от IDOR);
 *  - admin (web.admin + calculator.role:owner + feature.flag:mh_v2_referral):
 *    список с фильтрами member/order (для T13).
 * Read-only; суммы — integer USD-центы.
 */
class ReferralRewardController
{
    private const PAGE_LIMIT = 100;

    /** Мои реферальные премии (получатель = аутентифицированный участник). */
    public function mine(Request $request): JsonResponse
    {
        $member = $this->authMember($request);

        $rows = ReferralReward::query()
            ->where('beneficiary_member_id', $member->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_LIMIT)
            ->get([
                'id', 'order_id', 'source_member_id', 'beneficiary_member_id', 'depth',
                'tier_snapshot', 'rate_bps', 'base_bv_cents', 'gross_cents', 'net_cents',
                'status', 'paid_at', 'reversed_at',
            ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Admin: список премий с фильтрами (beneficiary/source/order). */
    public function index(Request $request): JsonResponse
    {
        $rows = ReferralReward::query()
            ->when($request->filled('beneficiary_member_id'),
                fn ($q) => $q->where('beneficiary_member_id', (int) $request->query('beneficiary_member_id')))
            ->when($request->filled('source_member_id'),
                fn ($q) => $q->where('source_member_id', (int) $request->query('source_member_id')))
            ->when($request->filled('order_id'),
                fn ($q) => $q->where('order_id', (int) $request->query('order_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_LIMIT)
            ->get();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    private function authMember(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        abort_if($member === null, 403, 'Требуется активированный участник');

        return $member;
    }
}
