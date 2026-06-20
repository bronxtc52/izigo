<?php

namespace Modules\Calculator\Services\Rank;

use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\Node;

interface IRankListener
{
    public function onNewRank(Node $node, Rank $rank): void;
}
