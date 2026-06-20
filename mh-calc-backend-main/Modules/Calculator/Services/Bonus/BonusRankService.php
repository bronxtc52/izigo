<?php

namespace Modules\Calculator\Services\Bonus;

use Modules\Calculator\Dto\Resource\Bonus\BonusDataRank;
use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\Node;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusRankService
{
    public function pay(LocaleEnum $currency, Node $nodeReceiver, Rank $rank, IBonusListener $listener): void
    {
        /** @TODO пересчитать в валюте отображения */
        $bonusAmount = $rank->bonus->rank_bonus_amount;
        if ($bonusAmount <= 0.01) {
            return;
        }

        $nodeReceiver->addRankBonus(new BonusDataRank($rank->id, $rank->name, $bonusAmount, $currency->currency));

        $listener->onBonusPay($nodeReceiver, $bonusAmount, __("calculator::marketing.bonus_rank", [
            'amount' => CurrencyFormatter::fiat($bonusAmount, $currency->currency),
            'rank' => $rank->name
        ]));
    }
}
