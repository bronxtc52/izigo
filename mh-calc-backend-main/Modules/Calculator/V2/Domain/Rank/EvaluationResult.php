<?php

namespace Modules\Calculator\V2\Domain\Rank;

use Modules\Calculator\V2\Domain\Policy\StatusRule;

/**
 * T05: результат прогона RankEvaluator — либо квалификация на achievedRank
 * (высший проходной, DEC-020: только повышение), либо fail по nextRank
 * (ближайший непройденный) с per-criterion разбором для снапшота.
 */
final class EvaluationResult
{
    /**
     * @param array<int, array{rule_id:string, actual:mixed, required:mixed,
     *                          passed:bool, reason:?string}> $criteria
     */
    public function __construct(
        public readonly bool $passed,
        public readonly ?StatusRule $achievedRank,
        public readonly ?string $variantUsed,
        public readonly ?RankAssignment $assignment,
        public readonly StatusRule $targetRank, // достигнутый либо ближайший непройденный
        public readonly array $criteria,
    ) {
    }
}
