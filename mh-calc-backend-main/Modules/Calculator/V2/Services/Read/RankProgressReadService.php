<?php

namespace Modules\Calculator\V2\Services\Read;

use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Domain\Policy\StatusRule;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\QualificationEvaluation;
use Modules\Calculator\V2\Services\Status\StatusReadService;

/**
 * mh-full-plan T14: read-проекция прогресса по лестнице 12 статусов для Mini App.
 *
 * НЕ реализует квалификационную логику заново — только проецирует:
 *  - каталог 12 статусов из PolicyVersion (коды/ordinal/пороги малой ветки);
 *  - достигнутые ранги из v2_rank_history (StatusReadService, «ранг навсегда» —
 *    после падения объёмов ранг в проекции не понижается);
 *  - разбор следующего статуса из последнего снапшота v2_qualification_evaluations
 *    (criteria_json: requirements-vs-actuals по каждому из 3 вариантов + PV малой
 *    ветки), фолбэк — требования из каталога с пустыми actuals.
 *
 * Все ранги/тиры/варианты — машинные коды (GOLD_MANAGER, ELITE, variant:A); RU/EN —
 * целиком на фронте. Деньги здесь не участвуют (статусы — PV/счётчики).
 */
class RankProgressReadService
{
    public function __construct(private readonly StatusReadService $reader)
    {
    }

    public function progress(int $memberId, PolicyV2 $policy): array
    {
        $state = $this->reader->currentState($memberId);
        $achieved = $this->achievedMap($memberId);
        $currentOrdinal = $this->currentOrdinal($state);

        return [
            'state' => $state?->state ?? PartnerState::STATE_NONE,
            'current_rank_code' => $state?->current_rank_code,
            'current_rank_ordinal' => $currentOrdinal >= 0 ? $currentOrdinal : null,
            'grace_expires_at' => $state?->grace_expires_at?->toIso8601String(),
            'grace_outcome' => $state?->grace_outcome,
            'ladder' => $this->ladder($policy, $achieved, $state?->current_rank_code),
            'next' => $this->next($memberId, $policy, $currentOrdinal),
            'tier' => $this->tier($state, $policy),
        ];
    }

    /**
     * Все 12 статусов в каноническом порядке ordinal с флагами achieved/current
     * и порогом малой ветки. Чистая проекция каталога политики.
     */
    private function ladder(PolicyV2 $policy, array $achieved, ?string $currentCode): array
    {
        $rows = [];
        foreach ($policy->statuses() as $status) {
            $code = $status->code->value;
            $rows[] = [
                'code' => $code,
                'ordinal' => $status->ordinal,
                'achieved' => isset($achieved[$code]),
                'achieved_at' => $achieved[$code] ?? null,
                'is_current' => $currentCode !== null && $code === $currentCode,
                'small_branch_pv_min' => $status->smallBranchPvMin,
            ];
        }
        usort($rows, static fn ($a, $b) => $a['ordinal'] <=> $b['ordinal']);

        return $rows;
    }

    /**
     * Разбор следующего статуса (current+1). Источник actuals — последний снапшот
     * квалификации по этому таргету (если он ещё актуален, т.е. ordinal таргета выше
     * текущего); иначе — требования каталога с actual=null (оценок ещё не было).
     * На вершине лестницы next=null.
     */
    private function next(int $memberId, PolicyV2 $policy, int $currentOrdinal): ?array
    {
        $target = null;
        foreach ($policy->statuses() as $status) {
            if ($status->ordinal === $currentOrdinal + 1) {
                $target = $status;
                break;
            }
        }
        if ($target === null) {
            return null; // достигнута вершина
        }

        $snapshot = $this->latestSnapshotFor($memberId, $target->code->value, $currentOrdinal);
        $criteria = is_array($snapshot?->criteria_json) ? $snapshot->criteria_json : [];
        $byRule = [];
        foreach ($criteria as $c) {
            if (isset($c['rule_id'])) {
                $byRule[$c['rule_id']] = $c;
            }
        }

        return [
            'rank_code' => $target->code->value,
            'rank_ordinal' => $target->ordinal,
            'source' => $snapshot !== null ? 'evaluation' : 'catalog',
            'evaluated_at' => $snapshot?->as_of?->toIso8601String(),
            'small_branch_pv' => $this->smallBranchProgress($target, $byRule, $snapshot),
            'referrals' => $this->referralsProgress($target, $byRule),
            'variants' => $this->variantsProgress($target, $byRule),
        ];
    }

    /** Прогресс малой ветки PV: required из каталога, actual из снапшота (decimal-строка). */
    private function smallBranchProgress(StatusRule $target, array $byRule, ?QualificationEvaluation $snapshot): ?array
    {
        if ($target->smallBranchPvMin === null) {
            return null;
        }
        $rule = $byRule['small_branch_pv'] ?? null;
        $actual = $rule['actual'] ?? ($snapshot?->small_branch_pv);

        return [
            'required' => $target->smallBranchPvMin,
            'actual' => $actual !== null ? (string) $actual : null,
            'satisfied' => $rule['passed'] ?? null,
        ];
    }

    /** Прогресс по рефералам 1-й линии (CONSULTANT/MANAGER/BRONZE): required/actual/satisfied. */
    private function referralsProgress(StatusRule $target, array $byRule): ?array
    {
        $required = $target->qualifiedReferralsMin ?? $target->directReferralsMin;
        if ($required === null) {
            return null;
        }
        $rule = $byRule['qualified_l1_referrals'] ?? null;

        return [
            'required' => $required,
            'actual' => isset($rule['actual']) ? (int) $rule['actual'] : null,
            'satisfied' => $rule['passed'] ?? null,
        ];
    }

    /**
     * Три (или сколько задано) варианта квалификации SILVER_MANAGER+: required слотов
     * (anchor+support) и actual из снапшота (сколько кандидатов из РАЗНЫХ ветвей
     * закрыто). distinct_root_branches — пометка «из разных ветвей» для фронта.
     */
    private function variantsProgress(StatusRule $target, array $byRule): array
    {
        $out = [];
        foreach ($target->variants as $variant) {
            $rule = $byRule['variant_' . $variant->code] ?? null;
            $out[] = [
                'code' => $variant->code,
                'anchor_rank' => $target->anchorRank?->value,
                'support_rank' => $target->supportRank?->value,
                'anchor_count' => $variant->anchorCount,
                'support_count' => $variant->supportCount,
                'required_slots' => $variant->anchorCount + $variant->supportCount,
                'actual_slots' => isset($rule['actual']) && $rule['actual'] !== null ? (int) $rule['actual'] : null,
                'distinct_root_branches' => $variant->distinctRootBranches,
                'satisfied' => $rule['passed'] ?? null,
            ];
        }

        return $out;
    }

    private function tier(?PartnerState $state, PolicyV2 $policy): array
    {
        return [
            'code' => $state?->current_tier,
            'personal_pv' => $state?->personal_pv_total ?? '0',
            'thresholds' => array_map(fn ($t) => [
                'code' => $t->code,
                'min_pv' => $t->minPv,
                'max_pv_exclusive' => $t->maxPvExclusive,
            ], $policy->tiers()),
        ];
    }

    /**
     * Последний снапшот оценки по таргету, ещё актуальный (target выше текущего ранга).
     * Возвращает null, если оценок под этот таргет не было или таргет уже пройден.
     */
    private function latestSnapshotFor(int $memberId, string $targetCode, int $currentOrdinal): ?QualificationEvaluation
    {
        if (StatusCode::from($targetCode)->ordinal() <= $currentOrdinal) {
            return null;
        }

        return QualificationEvaluation::query()
            ->where('member_id', $memberId)
            ->where('target_rank_code', $targetCode)
            ->orderByDesc('as_of')
            ->orderByDesc('created_at')
            ->first();
    }

    /** @return array<string, ?string> rank_code => achieved_at ISO */
    private function achievedMap(int $memberId): array
    {
        $map = [];
        foreach ($this->reader->achievedRanks($memberId) as $row) {
            $at = $row['achieved_at'] ?? null;
            $map[$row['rank_code']] = $at instanceof \DateTimeInterface
                ? $at->format(\DATE_ATOM)
                : ($at !== null ? (string) $at : null);
        }

        return $map;
    }

    private function currentOrdinal(?PartnerState $state): int
    {
        return $state?->current_rank_code === null
            ? -1
            : StatusCode::from($state->current_rank_code)->ordinal();
    }
}
