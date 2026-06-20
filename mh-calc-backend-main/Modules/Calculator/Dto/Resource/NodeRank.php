<?php

namespace Modules\Calculator\Dto\Resource;

class NodeRank extends EventData
{
    public function __construct(public int $rank_id, public string $rank_name)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_new_rank", [
            'rank' => $this->rank_name
        ]);
    }
}
