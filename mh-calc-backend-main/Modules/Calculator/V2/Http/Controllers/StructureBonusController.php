<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Models\StructureBonus;

/**
 * T06: read-API структурной премии.
 *
 *  - cabinet (Mini App, telegram.auth + feature.flag:mh_plan_v2_miniapp): участник
 *    видит ТОЛЬКО СВОИ начисления (member из auth-атрибута, chosen id игнорируется —
 *    анти-IDOR структурно). Read-only.
 *  - admin (web.admin + feature.flag:mh_plan_v2_admin): список по периоду и
 *    breakdown/explanation по участнику (DEC-054). read — owner,finance (в роутах).
 */
class StructureBonusController
{
    /** Мои начисления структурной премии (история). */
    public function mine(Request $request): JsonResponse
    {
        $member = $this->authMember($request);

        $rows = StructureBonus::query()
            ->where('member_id', $member->id)
            ->orderByDesc('accrual_month')
            ->orderByDesc('period_id')
            ->get()
            ->map(fn (StructureBonus $b) => $this->publicRow($b))
            ->all();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Admin: строки структурной премии периода. */
    public function byPeriod(int $periodId): JsonResponse
    {
        $rows = StructureBonus::query()
            ->where('period_id', $periodId)
            ->orderBy('member_id')
            ->get()
            ->map(fn (StructureBonus $b) => $this->adminRow($b))
            ->all();

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Admin: breakdown/explanation одного участника за период (DEC-054). */
    public function breakdown(int $periodId, int $memberId): JsonResponse
    {
        $row = StructureBonus::query()
            ->where('period_id', $periodId)
            ->where('member_id', $memberId)
            ->first();

        if ($row === null) {
            return response()->json(['status' => 'error', 'message' => 'Начисление не найдено'], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->adminRow($row) + ['explanation' => $row->explanation],
        ]);
    }

    private function publicRow(StructureBonus $b): array
    {
        return [
            'period_id' => $b->period_id,
            'accrual_month' => $b->accrual_month,
            'rank_code' => $b->rank_code,
            'rate_bps' => $b->rate_bps,
            'matched_pv' => $b->matched_pv,
            'matched_bv_cents' => $b->matched_bv_cents,
            'gross_cents' => $b->gross_cents,
            'after_cap_cents' => $b->after_cap_cents,
            'net_cents' => $b->net_cents,
            'forfeited_cents' => $b->forfeited_cents,
            'status' => $b->status,
        ];
    }

    private function adminRow(StructureBonus $b): array
    {
        return $this->publicRow($b) + [
            'member_id' => $b->member_id,
            'policy_version_id' => $b->policy_version_id,
            'match_group_id' => $b->match_group_id,
            'half_cap_cents' => $b->half_cap_cents,
            'monthly_cap_cents' => $b->monthly_cap_cents,
            'cap_remaining_before_cents' => $b->cap_remaining_before_cents,
            'pool_coefficient' => $b->pool_coefficient,
            'pool_adjustment_cents' => $b->pool_adjustment_cents,
            'posting_idempotency_key' => $b->posting_idempotency_key,
        ];
    }

    private function authMember(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        abort_if($member === null, 403, 'Требуется активированный участник');

        return $member;
    }
}
