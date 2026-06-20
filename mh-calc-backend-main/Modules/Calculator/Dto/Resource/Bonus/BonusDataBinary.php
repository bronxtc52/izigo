<?php

namespace Modules\Calculator\Dto\Resource\Bonus;

use Modules\Calculator\Dto\Resource\EventData;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusDataBinary extends EventData
{
    public function __construct(public int   $initiator_id, public string $initiator_name,
                                public float $from_volume, public int $percent, public float $amount, public string $currency)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_bonus_binary", [
            'name' => $this->initiator_name,
            'percent' => $this->percent,
            'small_branch_volume' => $this->from_volume,
            'amount' => CurrencyFormatter::fiat($this->amount, $this->currency),
        ]);
    }
}
