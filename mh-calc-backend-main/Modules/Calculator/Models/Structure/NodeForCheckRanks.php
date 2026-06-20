<?php

namespace Modules\Calculator\Models\Structure;

use Modules\Calculator\Dto\RankTempData;


class NodeForCheckRanks
{
    public float $smallBranchVolumePV = 0;
    public int $personalInvited = 0;
    private array $personalInvitedByRank = [];

    /**
     * @param Node $node
     * @param int $maxNodeId максимальный id ноды, существующей на момент расчета
     */
    public function __construct(public Node $node, public int $maxNodeId)
    {
        $this->init();
    }

    /**
     * @return void
     */
    protected function init():void
    {
        $this->initSmallBranchVolume($this->node);
        $this->personalInvited = $this->getPersonalInvitedCount($this->node, $this->node->id);
    }

    /**
     * @param Node $node
     * @return float
     */
    public function initSmallBranchVolume(): void
    {
        $volumesList = [];
        foreach ($this->node->children as $child) {
            $volumesList[] = $this->getBranchVolume($child);
        }
        $this->smallBranchVolumePV = empty($volumesList) ? 0 : min($volumesList);
    }

    private function getBranchVolume(?Node $node):float
    {
        if (!$node || $node->id > $this->maxNodeId)
        {
            return 0;
        }

        $result = $node->pv_personal;
        foreach ($node->children as $child) {
            if ($child) {
                $result += $this->getBranchVolume($child);
            }
        }
        return $result;
    }

    /**
     * @param int $needRankId
     * @return int
     */
    public function getPersonalInvitedByRank(int $needRankId):int
    {
        if (!isset($this->personalInvitedByRank[$needRankId]))
        {
            $this->personalInvitedByRank[$needRankId] = $this->getPersonalInvitedInRankCount($this->node, $this->node->id, $needRankId);
        }
        return $this->personalInvitedByRank[$needRankId];
    }

    /**
     * Ищет лично приглашенных только среди нижестоящих по бинару, существующих на момент расчета.
     *
     * @param Node $node
     * @param int $sponsorId
     * @return int
     */
    public function getPersonalInvitedCount(Node $node, int $sponsorId): int
    {
        if ($node->id > $this->maxNodeId)
        {
            return 0;
        }
        $result = $node->sponsor_id == $sponsorId ? 1 : 0;
        foreach ($node->children as $child) {
            if ($child) {
                $result += $this->getPersonalInvitedCount($child, $sponsorId);
            }
        }
        return $result;
    }

    /**
     * Ищет лично приглашенных с указанным рангом только среди нижестоящих по бинару, существующих на момент расчета.
     *
     * @param Node $node
     * @param int $sponsorId
     * @param int $needRankId
     * @return int
     */
    private function getPersonalInvitedInRankCount(Node $node, int $sponsorId, int $needRankId): int
    {
        if ($node->id > $this->maxNodeId)
        {
            return 0;
        }

        $result = ($node->sponsor_id == $sponsorId && $node->rank_id >= $needRankId) ? 1 : 0;
        foreach ($node->children as $child) {
            if ($child) {
                $result += $this->getPersonalInvitedInRankCount($child, $sponsorId, $needRankId);
            }
        }
        return $result;
    }

}
