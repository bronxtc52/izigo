<?php

namespace Modules\Calculator\V2\Domain\Rank;

use Modules\Calculator\V2\Domain\Policy\StatusRule;

/**
 * T05: чистое ядро лестницы статусов (CAL-RANK-001, без БД): сверху вниз по
 * каталогу, PV-гейт малой ветки, перебор вариантов через DistinctBranchAssigner;
 * только повышение (DEC-020 «ранг навсегда» — evaluator никогда не возвращает
 * ранг ниже текущего). CLIENT (ordinal 0) достигается жизненным циклом
 * (ClientLifecycleService), не оценкой.
 *
 * PV сравнивается bccomp по decimal-строкам (149999.99 < 150000 — граница спеки).
 */
class RankEvaluator
{
    public function __construct(private readonly DistinctBranchAssigner $assigner = new DistinctBranchAssigner())
    {
    }

    /**
     * @param array<string, StatusRule> $statuses каталог PolicyV2::statuses() (ordinal 0..11)
     */
    public function evaluate(array $statuses, QualificationInput $input): EvaluationResult
    {
        $ladder = array_values($statuses);
        usort($ladder, static fn (StatusRule $a, StatusRule $b) => $b->ordinal <=> $a->ordinal);

        $firstFail = null; // ближайший непройденный НАД текущим (для fail-снапшота)
        foreach ($ladder as $target) {
            if ($target->ordinal === 0 || $target->ordinal <= $input->currentRankOrdinal) {
                continue;
            }

            [$passed, $variant, $assignment, $criteria] = $this->check($target, $input);
            if ($passed) {
                return new EvaluationResult(
                    passed: true,
                    achievedRank: $target,
                    variantUsed: $variant,
                    assignment: $assignment,
                    targetRank: $target,
                    criteria: $criteria,
                );
            }
            // Идём сверху вниз — последний fail в цикле и есть ближайший к текущему рангу.
            $firstFail = [$target, $criteria];
        }

        if ($firstFail === null) {
            // Уже на вершине лестницы — оценивать нечего.
            $top = $ladder[0];

            return new EvaluationResult(false, null, null, null, $top, []);
        }

        [$target, $criteria] = $firstFail;

        return new EvaluationResult(false, null, null, null, $target, $criteria);
    }

    /**
     * @return array{0:bool, 1:?string, 2:?RankAssignment, 3:array}
     */
    private function check(StatusRule $target, QualificationInput $input): array
    {
        $criteria = [];
        $passed = true;

        // Гейт малой ветки (BR-RANK-001: пороги 1k..3M PV).
        if ($target->smallBranchPvMin !== null) {
            $ok = bccomp($input->smallBranchPv, (string) $target->smallBranchPvMin, 6) >= 0;
            $criteria[] = [
                'rule_id' => 'small_branch_pv',
                'actual' => $input->smallBranchPv,
                'required' => $target->smallBranchPvMin,
                'passed' => $ok,
                'reason' => $ok ? null : 'SMALL_BRANCH_PV_BELOW_THRESHOLD',
            ];
            $passed = $passed && $ok;
        }

        // CONSULTANT (1 квалифицированный реферал) и MANAGER/BRONZE (4/8 рефералов 1-й линии).
        $referralsMin = $target->qualifiedReferralsMin ?? $target->directReferralsMin;
        if ($referralsMin !== null) {
            $ok = $input->qualifiedL1Referrals >= $referralsMin;
            $criteria[] = [
                'rule_id' => 'qualified_l1_referrals',
                'actual' => $input->qualifiedL1Referrals,
                'required' => $referralsMin,
                'passed' => $ok,
                'reason' => $ok ? null : 'NOT_ENOUGH_QUALIFIED_REFERRALS',
            ];
            $passed = $passed && $ok;
        }

        // SILVER+ — варианты квалификации (лидеры в корневых ветвях).
        if ($target->variants !== []) {
            if (!$passed) {
                // PV-гейт не пройден — варианты не проверяем (fail уже зафиксирован).
                return [false, null, null, $criteria];
            }

            foreach ($target->variants as $variant) {
                $assignment = $this->assigner->assign($target, $variant, $input->candidates);
                $criteria[] = [
                    'rule_id' => 'variant_' . $variant->code,
                    'actual' => $assignment === null ? null : count($assignment->slots),
                    'required' => $variant->anchorCount + $variant->supportCount,
                    'passed' => $assignment !== null,
                    'reason' => $assignment === null ? 'NO_DISTINCT_BRANCH_ASSIGNMENT' : null,
                ];
                if ($assignment !== null) {
                    return [true, $variant->code, $assignment, $criteria];
                }
            }

            return [false, null, null, $criteria];
        }

        return [$passed, null, null, $criteria];
    }
}
