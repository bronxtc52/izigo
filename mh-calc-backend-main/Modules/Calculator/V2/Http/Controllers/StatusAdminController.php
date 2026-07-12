<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\QualificationEvaluation;
use Modules\Calculator\V2\Services\Status\RankEvaluationService;
use Modules\Calculator\V2\Services\Status\StatusReadService;

/**
 * T05: admin-обзор статусов (веб-админка, НЕ Mini App). RBAC на роутах
 * (v2_statuses.php): read — calculator.role:owner,finance; mutation (ручной
 * пересчёт участника) — owner-only (amendments NTH-1). Вся группа за
 * feature.flag:mh_v2_statuses.
 */
class StatusAdminController
{
    private const PAGE_LIMIT = 100;

    public function __construct(
        private readonly StatusReadService $reader,
        private readonly RankEvaluationService $ranks,
        private readonly PolicyVersionResolver $policyResolver,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Статус участника + достигнутые ранги. */
    public function show(int $memberId): JsonResponse
    {
        $state = $this->reader->currentState($memberId);
        abort_if($state === null, 404, 'Статус не найден');

        return response()->json(['status' => 'success', 'data' => [
            'state' => $state,
            'ranks' => $this->reader->achievedRanks($memberId),
        ]]);
    }

    /** Список оценок квалификации участника (новые сверху). */
    public function evaluations(Request $request, int $memberId): JsonResponse
    {
        $rows = QualificationEvaluation::query()
            ->where('member_id', $memberId)
            ->orderByDesc('created_at')
            ->limit(self::PAGE_LIMIT)
            ->get(['id', 'target_rank_code', 'as_of', 'variant_used', 'passed', 'small_branch_pv', 'trigger', 'created_at']);

        return response()->json(['status' => 'success', 'data' => $rows]);
    }

    /** Детали одной оценки: квалифаеры + корневые ветви + per-criterion. */
    public function evaluation(string $evaluationId): JsonResponse
    {
        $eval = QualificationEvaluation::query()->findOrFail($evaluationId);

        return response()->json(['status' => 'success', 'data' => $eval]);
    }

    /**
     * Ручной пересчёт участника (owner-only): под advisory-lock активаций
     * (внешний оркестратор), аудит-строка обязательна.
     */
    public function recompute(Request $request, int $memberId): JsonResponse
    {
        $actorId = $request->attributes->get('member')?->id;
        $policy = $this->policyResolver->current();
        $at = CarbonImmutable::now();

        $new = DB::transaction(function () use ($memberId, $at, $policy) {
            app(ActivationService::class)->acquireActivationLock();

            return $this->ranks->evaluateMember($memberId, $at, $policy, QualificationEvaluation::TRIGGER_MANUAL);
        });

        $this->audit->record($actorId, 'v2.status.recompute', 'member', $memberId, null, ['new_ranks' => $new]);

        return response()->json(['status' => 'success', 'data' => [
            'member_id' => $memberId,
            'new_ranks' => $new,
            'state' => PartnerState::query()->find($memberId),
        ]]);
    }
}
