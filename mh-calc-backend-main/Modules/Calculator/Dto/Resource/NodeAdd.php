<?php

namespace Modules\Calculator\Dto\Resource;

class NodeAdd extends EventData
{
    /**
     * @param int $node_id
     * @param string $node_name
     * @param int $branch_index
     * @param string $branch_title
     * @param string $package
     */
    public function __construct(public int $node_id, public string $node_name,
                                public int $branch_index, public string $branch_title, public string $package)
    {
    }

    public function getDetail(): string
    {
        //'details_new_node' => 'Постановка пользователя “:name” в :branch',
        return __("calculator::marketing.details_new_node", [
            'package' => $this->package,
            'name' => $this->node_name,
            'branch' => $this->branch_title
        ]);
    }
}
