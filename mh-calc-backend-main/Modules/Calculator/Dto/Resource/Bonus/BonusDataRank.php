<?php

namespace Modules\Calculator\Dto\Resource\Bonus;

use Modules\Calculator\Dto\Resource\EventData;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusDataRank extends EventData
{
    public function __construct(public int $rank_id, public string $rank_name, public float $amount, public string $currency)
    {
    }

    public function getDetail(): string
    {
        return __("calculator::marketing.details_bonus_rank", [
            'rank' => $this->rank_name,
            'amount' => CurrencyFormatter::fiat($this->amount, $this->currency),
        ]);
    }


}
