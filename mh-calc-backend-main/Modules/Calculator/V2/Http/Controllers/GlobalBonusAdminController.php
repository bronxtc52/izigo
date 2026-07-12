<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPayout;
use Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService;

/**
 * T09: admin-отчёты глобального бонуса + ручной пересчёт draft-месяца.
 * RBAC на маршрутах (v2_global_bonus.php): read — calculator.role:owner,finance;
 * mutation (recompute) — calculator.role:owner; группа под web.admin +
 * feature.flag:mh_v2_global_bonus (deny-by-default). Только чтение снапшотов и
 * идемпотентный пересчёт — денег контроллер не постит (выплата — квартальный job T04).
 */
class GlobalBonusAdminController
{
    public function __construct(
        private readonly GlobalBonusMonthlyService $service,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Список месяцев глобального бонуса (новые сверху). */
    public function months(): JsonResponse
    {
        $months = GlobalBonusMonth::query()
            ->with('period:id,code,status,starts_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $months->map(fn (GlobalBonusMonth $m) => $this->presentMonthSummary($m))->all(),
        ]);
    }

    /** Детали месяца: пулы, квалификации, аллокации (member + unallocated). */
    public function month(string $code): JsonResponse
    {
        $month = $this->findMonthByCode($code);
        if ($month === null) {
            return response()->json(['status' => 'error', 'message' => "Месяц {$code} не рассчитан"], 404);
        }

        $pools = $month->pools()->orderBy('id')->get();
        $allocationsByPool = $month->allocations()->orderBy('pool_id')->orderByRaw('member_id NULLS LAST')->get()->groupBy('pool_id');

        return response()->json([
            'status' => 'success',
            'data' => $this->presentMonthSummary($month) + [
                'pools' => $pools->map(fn ($p) => [
                    'pool_rank' => $p->pool_rank,
                    'rate_bps' => (int) $p->rate_bps,
                    'pool_amount_cents' => (int) $p->pool_amount_cents,
                    'total_shares' => (int) $p->total_shares,
                    'allocated_cents' => (int) $p->allocated_cents,
                    'unallocated_cents' => (int) $p->unallocated_cents,
                    'unallocated_reason' => $p->unallocated_reason,
                    'allocations' => ($allocationsByPool[$p->id] ?? collect())->map(fn (GlobalBonusAllocation $a) => [
                        'member_id' => $a->member_id,
                        'kind' => $a->kind,
                        'shares' => (int) $a->shares,
                        'raw_cents' => (int) $a->raw_cents,
                        'capped_cents' => (int) $a->capped_cents,
                        'final_cents' => (int) $a->final_cents,
                        'status' => $a->status,
                    ])->values()->all(),
                ])->all(),
                'qualifications' => $month->qualifications()->orderBy('member_id')->get()->map(fn ($q) => [
                    'member_id' => $q->member_id,
                    'achieved_rank' => $q->achieved_rank,
                    'referral_tree_pv' => $q->referral_tree_pv,
                    'base_pv' => $q->base_pv,
                    'max_shares' => (int) $q->max_shares,
                    'shares' => (int) $q->shares,
                ])->all(),
            ],
        ]);
    }

    /** Квартал: факт выплат + предпросмотр по 3 месяцам (Σ final_cents по участнику). */
    public function quarter(string $code): JsonResponse
    {
        if (! preg_match('/^\d{4}-Q[1-4]$/', $code)) {
            return response()->json(['status' => 'error', 'message' => "Некорректный код квартала: {$code}"], 422);
        }
        $quarter = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_QUARTER)->where('code', $code)->first();
        if ($quarter === null) {
            return response()->json(['status' => 'error', 'message' => "Квартал {$code} не найден"], 404);
        }

        $payouts = GlobalBonusPayout::query()
            ->where('quarter_period_id', $quarter->id)->orderBy('member_id')->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'quarter' => ['id' => $quarter->id, 'code' => $quarter->code, 'status' => $quarter->status],
                'payouts' => $payouts->map(fn (GlobalBonusPayout $p) => [
                    'member_id' => $p->member_id,
                    'amount_cents' => (int) $p->amount_cents,
                    'status' => $p->status,
                    'posted_at' => $p->posted_at?->toIso8601ZuluString(),
                ])->all(),
                'total_paid_cents' => (int) $payouts->where('status', GlobalBonusPayout::STATUS_POSTED)->sum('amount_cents'),
            ],
        ]);
    }

    /** Ручной идемпотентный пересчёт draft-месяца (owner-only). Финальный → 409. */
    public function recompute(Request $request, string $code): JsonResponse
    {
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)->where('code', $code)->first();
        if ($period === null) {
            return response()->json(['status' => 'error', 'message' => "Месячный период {$code} не найден"], 404);
        }

        $existing = GlobalBonusMonth::query()->where('month_period_id', $period->id)->first();
        if ($existing !== null && $existing->isFinal()) {
            return response()->json(['status' => 'error', 'message' => "Месяц {$code} финализирован — пересчёт запрещён"], 409);
        }

        $month = $this->service->allocateForMonth($period);

        $this->audit->recordSafe(
            $this->viewer($request)?->id,
            'v2.global_bonus.recompute',
            'v2_global_bonus_month',
            $month->id,
            null,
            ['code' => $code, 'global_bv_cents' => $month->global_bv_cents],
        );

        return response()->json(['status' => 'success', 'data' => $this->presentMonthSummary($month)]);
    }

    private function findMonthByCode(string $code): ?GlobalBonusMonth
    {
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)->where('code', $code)->first();
        if ($period === null) {
            return null;
        }

        return GlobalBonusMonth::query()->where('month_period_id', $period->id)->first();
    }

    private function presentMonthSummary(GlobalBonusMonth $month): array
    {
        return [
            'id' => $month->id,
            'month_period_id' => $month->month_period_id,
            'code' => $month->period?->code,
            'policy_version_id' => $month->policy_version_id,
            'global_bv_cents' => (int) $month->global_bv_cents,
            'status' => $month->status,
            'computed_at' => $month->computed_at?->toIso8601ZuluString(),
            'finalized_at' => $month->finalized_at?->toIso8601ZuluString(),
        ];
    }

    private function viewer(Request $request): ?Member
    {
        return $request->attributes->get('member');
    }
}
