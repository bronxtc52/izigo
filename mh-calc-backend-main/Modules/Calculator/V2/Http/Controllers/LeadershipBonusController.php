<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Models\LeadershipBonusLine;

/**
 * T08: read-API лидерского бонуса.
 *  - cabinet (Mini App, telegram.auth + feature.flag:mh_v2_leadership): участник видит
 *    ТОЛЬКО СВОИ начисления (receiver = auth-member; chosen id игнорируется — защита от
 *    IDOR), исключения не показываются партнёру;
 *  - admin (web.admin + calculator.role:owner + feature.flag): отчёт по периоду —
 *    начисления, суммы, исключения с причинами и blocking_member (кормит T13).
 * Read-only; суммы — integer USD-центы.
 */
class LeadershipBonusController
{
    private const PAGE_LIMIT = 200;

    /** Мои лидерские начисления (получатель = аутентифицированный участник). */
    public function mine(Request $request): JsonResponse
    {
        $member = $this->authMember($request);

        $rows = LeadershipBonusLine::query()
            ->where('receiver_member_id', $member->id)
            ->whereIn('status', [LeadershipBonusLine::STATUS_ACCRUED, LeadershipBonusLine::STATUS_POSTED])
            ->when($request->filled('period_id'),
                fn ($q) => $q->where('period_id', (int) $request->query('period_id')))
            ->orderByDesc('period_id')
            ->orderByDesc('id')
            ->limit(self::PAGE_LIMIT)
            ->get([
                'id', 'period_id', 'source_member_id', 'source_structure_bonus_id', 'depth',
                'receiver_rank_key', 'receiver_tier', 'rate_bp', 'base_cents', 'amount_cents',
                'status',
            ]);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Admin: отчёт по периоду (начисления + исключения с причинами; для T13). */
    public function index(Request $request): JsonResponse
    {
        $rows = LeadershipBonusLine::query()
            ->when($request->filled('period_id'),
                fn ($q) => $q->where('period_id', (int) $request->query('period_id')))
            ->when($request->filled('receiver_member_id'),
                fn ($q) => $q->where('receiver_member_id', (int) $request->query('receiver_member_id')))
            ->when($request->filled('source_member_id'),
                fn ($q) => $q->where('source_member_id', (int) $request->query('source_member_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('period_id')
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
