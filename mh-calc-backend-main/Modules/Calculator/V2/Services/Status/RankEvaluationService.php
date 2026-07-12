<?php

namespace Modules\Calculator\V2\Services\Status;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calculator\V2\Contracts\BinaryVolumeReaderInterface;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Domain\Rank\EvaluationResult;
use Modules\Calculator\V2\Domain\Rank\QualificationInput;
use Modules\Calculator\V2\Domain\Rank\RankCandidate;
use Modules\Calculator\V2\Domain\Rank\RankEvaluator;
use Modules\Calculator\V2\Domain\Rank\RootBranchResolver;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\QualificationEvaluation;
use Modules\Calculator\V2\Services\Volume\ActivationLockGuard;

/**
 * T05: оркестратор оценки лестницы (CAL-RANK-001) — собирает входы (малая ветка
 * lifetime PV из BinaryVolumeReaderInterface, реферальное поддерево из
 * members.sponsor_id, ранги кандидатов из v2_partner_states/v2_rank_history),
 * прогоняет чистый RankEvaluator, персистит снапшот (v2_qualification_evaluations)
 * и при повышении — v2_rank_history (при скачке через ранги — строка на КАЖДЫЙ
 * пройденный ранг с одним evaluation_id, DEC-040), обновляет current_rank_code.
 *
 * Ранг навсегда (DEC-020): только повышение, unique(member_id, rank_code) страхует
 * идемпотентность (повторная оценка без изменений сети не плодит дублей).
 * Выполняется под ACTIVATION_LOCK оркестратора: assertLockHeld.
 */
class RankEvaluationService
{
    public function __construct(
        private readonly ActivationLockGuard $lockGuard,
        private readonly BinaryVolumeReaderInterface $volumeReader,
        private readonly RootBranchResolver $rootBranchResolver,
        private readonly RankEvaluator $evaluator,
        private readonly ClientLifecycleService $lifecycle,
    ) {
    }

    /**
     * Оценить участника и весь его sponsor-аплайн (покупка на глубине N меняет
     * малую ветку всех предков). Возвращает список впервые достигнутых рангов
     * (для тестов/наград T10 — они и так триггерятся по rank_history).
     *
     * @return array<int, string[]> member_id => коды новоприсвоенных рангов
     */
    public function evaluateAffectedUpline(int $memberId, CarbonImmutable $at, PolicyV2 $policy): array
    {
        $this->lockGuard->assertLockHeld();

        $result = [];
        foreach ($this->uplineIncluding($memberId) as $id) {
            $newRanks = $this->evaluateMember($id, $at, $policy, QualificationEvaluation::TRIGGER_ORDER);
            if ($newRanks !== []) {
                $result[$id] = $newRanks;
            }
        }

        return $result;
    }

    /**
     * Оценить одного участника; персистит evaluation, при повышении — rank_history.
     *
     * @return string[] коды новоприсвоенных рангов (пусто, если без повышения)
     */
    public function evaluateMember(int $memberId, CarbonImmutable $at, PolicyV2 $policy, string $trigger): array
    {
        $this->lockGuard->assertLockHeld();

        $state = $this->lifecycle->stateRow($memberId);
        // Лестница выше CONSULTANT доступна только активированным (CLIENT+ прошёл grace).
        if (!in_array($state->state, [PartnerState::STATE_CONSULTANT], true)
            && $this->currentOrdinal($state) < StatusCode::CONSULTANT->ordinal()) {
            return [];
        }

        $input = $this->buildInput($memberId, $state, $at, $policy);
        $result = $this->evaluator->evaluate($policy->statuses(), $input);

        $evaluation = $this->persistEvaluation($memberId, $at, $policy, $trigger, $input, $result);

        if (!$result->passed || $result->achievedRank === null) {
            return [];
        }

        return $this->recordAchievedRanks($memberId, $state, $result, $at, $policy, $evaluation->id);
    }

    private function buildInput(int $memberId, PartnerState $state, CarbonImmutable $at, PolicyV2 $policy): QualificationInput
    {
        $left = $this->volumeReader->leftLifetimePv($memberId, $at);
        $right = $this->volumeReader->rightLifetimePv($memberId, $at);
        $smallBranch = bccomp($left, $right, 6) <= 0 ? $left : $right;

        $sponsorById = $this->subtreeSponsorMap($memberId);
        $candidates = $this->buildCandidates($memberId, $sponsorById, $at);

        return new QualificationInput(
            memberId: $memberId,
            currentRankOrdinal: $this->currentOrdinal($state),
            smallBranchPv: $smallBranch,
            qualifiedL1Referrals: $this->lifecycle->qualifiedL1Referrals($memberId, $at),
            candidates: $candidates,
        );
    }

    /**
     * Кандидаты реферального поддерева получателя с их достигнутым рангом на $at
     * (из v2_rank_history — as-of) и корневой ветвью (BR-TREE-001).
     *
     * @param array<int, ?int> $sponsorById карта поддерева member_id => sponsor_id
     * @return RankCandidate[]
     */
    private function buildCandidates(int $receiverId, array $sponsorById, CarbonImmutable $at): array
    {
        $memberIds = array_values(array_filter(
            array_keys($sponsorById),
            fn (int $id) => $id !== $receiverId,
        ));
        if ($memberIds === []) {
            return [];
        }

        $rankByMember = $this->achievedRankOrdinals($memberIds, $at);

        $candidates = [];
        foreach ($memberIds as $id) {
            $rank = $rankByMember[$id] ?? null;
            if ($rank === null) {
                continue; // без достигнутого ранга кандидат ничего не закрывает
            }
            $root = $this->rootBranchResolver->rootBranchFor($receiverId, $id, $sponsorById);
            if ($root === null) {
                continue;
            }
            $candidates[] = new RankCandidate(
                memberId: $id,
                rankCode: $rank['code'],
                rankOrdinal: $rank['ordinal'],
                isLevelOne: ($sponsorById[$id] ?? null) === $receiverId,
                rootBranchMemberId: $root,
            );
        }

        return $candidates;
    }

    private function persistEvaluation(
        int $memberId,
        CarbonImmutable $at,
        PolicyV2 $policy,
        string $trigger,
        QualificationInput $input,
        EvaluationResult $result,
    ): QualificationEvaluation {
        $qualifiers = $result->assignment?->slots ?? [];
        $payload = [
            'member_id' => $memberId,
            'target_rank_code' => $result->targetRank->code->value,
            'as_of' => $at,
            'policy_version_id' => $policy->versionId(),
            'small_branch_pv' => $input->smallBranchPv,
            'variant_used' => $result->variantUsed,
            'passed' => $result->passed,
            'qualifiers_json' => $qualifiers,
            'criteria_json' => $result->criteria,
            'trigger' => $trigger,
        ];
        $payload['evidence_hash'] = hash('sha256', json_encode([
            $memberId, $result->targetRank->code->value, $at->format(DATE_ATOM),
            $input->smallBranchPv, $result->variantUsed, $result->passed, $qualifiers,
        ], JSON_UNESCAPED_UNICODE));

        return QualificationEvaluation::query()->create($payload + [
            'id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }

    /**
     * Записать все пройденные ранги от (current+1) до achieved включительно (DEC-040):
     * отдельная idempotent-строка на каждый, один evaluation_id. Возвращает
     * реально новоприсвоенные коды.
     *
     * @return string[]
     */
    private function recordAchievedRanks(
        int $memberId,
        PartnerState $state,
        EvaluationResult $result,
        CarbonImmutable $at,
        PolicyV2 $policy,
        string $evaluationId,
    ): array {
        $fromOrdinal = $this->currentOrdinal($state) + 1;
        $toOrdinal = $result->achievedRank->ordinal;

        $new = [];
        foreach ($policy->statuses() as $status) {
            if ($status->ordinal < $fromOrdinal || $status->ordinal > $toOrdinal) {
                continue;
            }
            $inserted = DB::table('v2_rank_history')->insertOrIgnore([
                'member_id' => $memberId,
                'rank_code' => $status->code->value,
                'rank_ordinal' => $status->ordinal,
                'achieved_at' => $at,
                'evaluation_id' => $evaluationId,
                'policy_version_id' => $policy->versionId(),
                'created_at' => now(),
            ]);
            if ($inserted > 0) {
                $new[] = $status->code->value;
            }
        }

        if ($result->achievedRank->ordinal > $this->currentOrdinal($state)) {
            $state->current_rank_code = $result->achievedRank->code->value;
            $state->save();
        }

        return $new;
    }

    /** Аплайн по sponsor_id включая самого участника (крошечный прод — простой цикл). */
    private function uplineIncluding(int $memberId): array
    {
        $chain = [];
        $node = $memberId;
        $seen = [];
        while ($node !== null && !isset($seen[$node])) {
            $seen[$node] = true;
            $chain[] = $node;
            $node = DB::table('members')->where('id', $node)->value('sponsor_id');
        }

        return $chain;
    }

    /**
     * Карта sponsor_id всего реферального ПОДДЕРЕВА участника (BFS вниз по sponsor_id).
     *
     * @return array<int, ?int> member_id => sponsor_id (включая корень $memberId)
     */
    private function subtreeSponsorMap(int $memberId): array
    {
        $map = [$memberId => DB::table('members')->where('id', $memberId)->value('sponsor_id')];
        $frontier = [$memberId];
        while ($frontier !== []) {
            $children = DB::table('members')
                ->whereIn('sponsor_id', $frontier)
                ->get(['id', 'sponsor_id']);
            $frontier = [];
            foreach ($children as $child) {
                if (!array_key_exists((int) $child->id, $map)) {
                    $map[(int) $child->id] = $child->sponsor_id === null ? null : (int) $child->sponsor_id;
                    $frontier[] = (int) $child->id;
                }
            }
        }

        return $map;
    }

    /**
     * Достигнутый ранг каждого участника на $at (as-of из v2_rank_history — высший
     * ordinal с achieved_at <= at).
     *
     * @param int[] $memberIds
     * @return array<int, array{code:string, ordinal:int}>
     */
    private function achievedRankOrdinals(array $memberIds, CarbonImmutable $at): array
    {
        $rows = DB::table('v2_rank_history')
            ->whereIn('member_id', $memberIds)
            ->where('achieved_at', '<=', $at)
            ->orderBy('member_id')
            ->orderByDesc('rank_ordinal')
            ->get(['member_id', 'rank_code', 'rank_ordinal']);

        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->member_id;
            if (!isset($out[$id])) {
                $out[$id] = ['code' => $row->rank_code, 'ordinal' => (int) $row->rank_ordinal];
            }
        }

        return $out;
    }

    private function currentOrdinal(PartnerState $state): int
    {
        return $state->current_rank_code === null
            ? -1
            : StatusCode::from($state->current_rank_code)->ordinal();
    }
}
