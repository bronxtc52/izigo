<?php

namespace Modules\Calculator\Dto\Resource\Bonus;

use Modules\Calculator\Dto\Resource\EventData;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusDataLeader extends EventData
{
    public function __construct(public int   $initiator_id, public string $initiator_name,
                                public float $from_binary_bonus_amount, public int $level, public int $percent, public float $amount, public string $currency)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_bonus_leader", [
            'name' => $this->initiator_name,
            'level' => $this->level,
            'from_bonus_amount' => $this->from_binary_bonus_amount,
            'percent' => $this->percent,
            'amount' => CurrencyFormatter::fiat($this->amount, $this->currency),
        ]);
    }


}
