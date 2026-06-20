<?php

namespace Modules\Calculator\Domain\Rank;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Снимок параметров узла для квалификации ранга «на момент» постановки узла maxNodeId:
 * учитываются только узлы с id <= maxNodeId (темпоральная отсечка).
 * Малая ветка = min по двум ногам (отсутствующая = 0), как в бинар-бонусе.
 */
final class RankSnapshot
{
    public readonly Pv $smallBranchVolume;
    public readonly int $personalInvited;
    /** @var array<int,int> needRankId => count (memo) */
    private array $personalInvitedByRank = [];

    public function __construct(
        private readonly MemberNode $node,
        private readonly int $maxNodeId,
    ) {
        $legs = [];
        foreach ($node->children as $child) {
            $legs[] = $this->branchVolume($child);
        }
        while (count($legs) < 2) {
            $legs[] = Pv::zero();
        }
        $this->smallBranchVolume = Pv::min(...$legs);
        $this->personalInvited = $this->countInvited($node, $node->id, null);
    }

    public function invitedByRank(int $needRankId): int
    {
        return $this->personalInvitedByRank[$needRankId]
            ??= $this->countInvited($this->node, $this->node->id, $needRankId);
    }

    /** Сумма pvPersonal по placement-поддереву узла (id <= maxNodeId). */
    private function branchVolume(?MemberNode $node): Pv
    {
        if ($node === null || $node->id > $this->maxNodeId) {
            return Pv::zero();
        }
        $sum = $node->pvPersonal;
        foreach ($node->children as $child) {
            $sum = $sum->add($this->branchVolume($child));
        }
        return $sum;
    }

    /**
     * Кол-во лично приглашённых (sponsorId == корня) в placement-поддереве (id <= maxNodeId).
     * Если $needRankId != null — только с rankId >= needRankId.
     */
    private function countInvited(MemberNode $node, int $sponsorId, ?int $needRankId): int
    {
        if ($node->id > $this->maxNodeId) {
            return 0;
        }
        $match = $node->sponsorId === $sponsorId
            && ($needRankId === null || $node->rankId >= $needRankId);
        $count = $match ? 1 : 0;
        foreach ($node->children as $child) {
            $count += $this->countInvited($child, $sponsorId, $needRankId);
        }
        return $count;
    }
}
