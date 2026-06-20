<?php

namespace Modules\Calculator\Dto\Resource\Bonus;

use Modules\Calculator\Dto\Resource\EventData;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusDataReferral extends EventData
{
    public function __construct(public int $initiator_id, public string $initiator_name,
                                public int $level, public int $percent, public float $amount, public string $currency)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_bonus_referral", [
            'name' => $this->initiator_name,
            'percent' => $this->percent,
            'level' => $this->level,
            'amount' => CurrencyFormatter::fiat($this->amount, $this->currency),
        ]);
    }


}
