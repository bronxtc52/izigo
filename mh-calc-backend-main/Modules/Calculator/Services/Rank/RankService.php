<?php

namespace Modules\Calculator\Services\Rank;

use Modules\Calculator\Dto\RankTempData;
use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\NodeForCheckRanks;

class RankService
{
    /**
     * @param int $maxNodeId максимальный id ноды, существующей на момент расчета
     * @param Node $node
     * @param IRankListener $listener
     * @param string $locale
     * @return void
     */
    public function checkWithSponsors(int $maxNodeId, Node $node, IRankListener $listener, string $locale): void
    {
        //чтобы при проверке каждого ранга не считать одни и те же параметры,
        //сразу выяснили объем меньшей ветки и кол-во приглашенных,
        //имеющиеся до постановки $node
        $nodeForCheck = new NodeForCheckRanks($node, $maxNodeId);

        $rankMap = Rank::getMap($locale);
        foreach ($rankMap as $rank) {
            if ($node->rank_id < $rank->id) {
                $checker = RankCheck::getChecker($maxNodeId, $rank, $nodeForCheck);
                if ($checker->check()) {
                    $node->setRank($rank->id, $rank->name);
                    $listener->onNewRank($node, $rank);
                }
            }
        }

        if ($node->parent) {
            $this->checkWithSponsors($maxNodeId, $node->parent, $listener, $locale);
        }
    }
}
