<?php

namespace Modules\Calculator\Domain\Rank;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Plan\Rank;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\Result\CalculationResult;

/**
 * Квалификация рангов: повышает ранг узла при выполнении условий и рекурсивно
 * проверяет его бинар-родителей. maxNodeId фиксирует «момент» (темпоральная отсечка).
 * Каждое новое достижение ранга начисляет разовый ранговый бонус.
 */
final class RankQualifier
{
    public function __construct(private readonly Plan $plan)
    {
    }

    public function qualifyUpchain(MemberNode $node, int $maxNodeId, CalculationResult $result): void
    {
        $this->qualify($node, $maxNodeId, $result);
        if ($node->parent) {
            $this->qualifyUpchain($node->parent, $maxNodeId, $result);
        }
    }

    private function qualify(MemberNode $node, int $maxNodeId, CalculationResult $result): void
    {
        $snapshot = new RankSnapshot($node, $maxNodeId);

        foreach ($this->plan->ranksOrdered() as $rank) {
            if ($node->rankId < $rank->id && $this->passes($rank, $snapshot)) {
                $node->rankId = $rank->id;
                $result->addRankAchievement($node->id, $rank->id);
                if ($rank->bonus->isPositive()) {
                    $result->addBonus(new BonusLine(BonusLine::RANK, $node->id, $rank->bonus, meta: ['rankId' => $rank->id]));
                }
            }
        }
    }

    private function passes(Rank $rank, RankSnapshot $snapshot): bool
    {
        if (!$rank->smallBranchVolume->isZero()
            && !$snapshot->smallBranchVolume->greaterThanOrEqual($rank->smallBranchVolume)) {
            return false;
        }
        if ($rank->personalCount > 0 && $snapshot->personalInvited < $rank->personalCount) {
            return false;
        }
        if ($rank->personalInRankCount > 0
            && $snapshot->invitedByRank($rank->personalInRankId) < $rank->personalInRankCount) {
            return false;
        }
        return true;
    }
}
