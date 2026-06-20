<?php

namespace Modules\Calculator\Services\Rank;

use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\NodeForCheckRanks;

class RankCheck
{
    public static function getChecker(int $maxNodeId, Rank $rank, NodeForCheckRanks $nodeCheck): self
    {
        return new self($maxNodeId, $rank, $nodeCheck);
    }

    /**
     * @param int $maxNodeId максимальный id ноды, существующей на момент расчета
     * @param Rank $rank
     * @param NodeForCheckRanks $nodeCheck
     */
    public function __construct(public int $maxNodeId, public Rank $rank, public NodeForCheckRanks $nodeCheck)
    {
    }

    public function check(): bool
    {
        $result = $this->checkBinarySmallBranch();
        $result = $result && $this->checkPersonalInvited();
        $result = $result && $this->checkPersonalInRank();
        return $result;
    }

    protected function checkBinarySmallBranch(): bool
    {
        if ($this->rank->binary_small_branch_volume) {
            //print "checkBinarySmallBranch for {$this->nodeCheck->node->id} has volume: " . $this->nodeCheck->smallBranchVolumePV . "\n";
            return $this->nodeCheck->smallBranchVolumePV >= $this->rank->binary_small_branch_volume;
        }
        return true;
    }

    protected function checkPersonalInvited(): bool
    {
        if ($this->rank->personal_count) {
            //print "checkPersonalInvited for {$this->nodeCheck->node->id} has invited_count: " . $this->nodeCheck->personalInvited . "\n";
            return $this->nodeCheck->personalInvited >= $this->rank->personal_count;
        }
        return true;
    }

    protected function checkPersonalInRank(): bool
    {
        if ($this->rank->personal_in_rank_count) {
            $hasCount = $this->nodeCheck->getPersonalInvitedByRank($this->rank->personal_in_rank_id);
            //print "checkPersonalInRank for {$this->nodeCheck->node->id} has invited_count {$hasCount} with rank {$this->rank->personal_in_rank_id}:\n";
            return $hasCount >= $this->rank->personal_in_rank_count;
        }
        return true;
    }

}
