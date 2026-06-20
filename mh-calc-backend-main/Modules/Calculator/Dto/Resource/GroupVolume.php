<?php

namespace Modules\Calculator\Dto\Resource;


use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class GroupVolume extends EventData
{
    /**
     * @param float $bv
     * @param float $pv
     * @param int $branch_index от нуля
     * @param string $branch_title
     * @param int $initiator_id
     * @param string $initiator_name
     */
    public function __construct(public float $bv, public float $pv,
                                public int   $branch_index, public string $branch_title,
                                public int   $initiator_id, public string $initiator_name)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_volumes_group", [
            'pv_amount' => CurrencyFormatter::pv($this->pv),
            'bv_amount' => CurrencyFormatter::bv($this->bv),
            'branch' => $this->branch_title
        ]);
    }
}
