<?php

namespace Modules\Calculator\Dto;

use Modules\Calculator\Models\Structure\Structure;
use Spatie\LaravelData\Data;

class NodeDeleteData extends Data
{
    /**
     * @param Structure $structure
     * @param int $node_id
     */
    public function __construct(
        public Structure $structure,
        public int       $node_id
    )
    {
    }


}
