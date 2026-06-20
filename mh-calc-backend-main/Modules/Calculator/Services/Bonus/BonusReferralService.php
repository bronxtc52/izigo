<?php

namespace Modules\Calculator\Services\Bonus;

use Modules\Calculator\Dto\Resource\Bonus\BonusDataReferral;
use Modules\Calculator\Models\Structure\Node;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusReferralService
{
    const INITIATOR_MAX_PV = 600;
    const DEPTH = 2;

    public function pay(LocaleEnum $currency, Node $nodeInitiator, IBonusListener $listener): void
    {
        //print "BonusReferralService от {$nodeInitiator->name}\n";

        $purchaseAmount = $nodeInitiator->package($currency->value)?->volume?->bv ?? 0;

        if (!$purchaseAmount) {
            //print "BonusReferralService !purchaseAmount\n";
            return;
        }

        //Отменили условие: бонус не выплачивается ... когда они достигли 600 PV (уровень партнерского контракта ELITE)
        //if ($nodeInitiator->pv_personal >= self::INITIATOR_MAX_PV) {
            //print "BonusReferralService pv_personal >= {$nodeInitiator->pv_personal}\n";
        //    return;
        //}

        /** @var Node $sponsor - вышестоящий по ЛП */
        $sponsor = $nodeInitiator->sponsor;
        $level = 0;
        while ($sponsor && ++$level <= self::DEPTH) {
            $this->payForNode($sponsor, $currency, $nodeInitiator, $purchaseAmount, $level, $listener);
            $sponsor = $sponsor->sponsor;
        }
    }

    private function payForNode(Node $receiver, LocaleEnum $currency, Node $nodeInitiator, float $purchaseAmount, int $level, IBonusListener $listener): void
    {
        $percent = $this->getPercent($currency->value, $level, $receiver);
        $bonusAmount = $percent > 0 ? $purchaseAmount / 100 * $percent : 0;

        if ($bonusAmount) {
            $receiver->addReferralBonus(new BonusDataReferral($nodeInitiator->id, $nodeInitiator->name,
                $level, $percent, $bonusAmount, $currency->currency));
            $listener->onBonusPay($receiver, $bonusAmount, __("calculator::marketing.bonus_referral", [
                'percent' => $percent,
                'amount' => CurrencyFormatter::fiat($bonusAmount, $currency->currency),
                'level' => $level
            ]));
        }
    }

    public function getPercent(string $locale, int $level, Node $nodeReceiver): float
    {
        $config = $this->getConfig();
        return $config[$nodeReceiver->package($locale)?->sort][$level] ?? 0;
    }

    private function getConfig(): array
    {
        return [

            //Пакет Start 1 линия 10 %
            1 => [1 => 10, 2 => 0],

            //Пакет Business 1 линия 10 % | 2 линия 5 %
            2 => [1 => 10, 2 => 5],

            //Пакет Elite 1 линия 10 % | 2 линия 8 %
            3 => [1 => 10, 2 => 8],
        ];
    }
}
