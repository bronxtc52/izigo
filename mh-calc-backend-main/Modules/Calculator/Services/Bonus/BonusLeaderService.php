<?php

namespace Modules\Calculator\Services\Bonus;

use Modules\Calculator\Dto\Resource\Bonus\BonusDataLeader;
use Modules\Calculator\Models\Structure\Node;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusLeaderService
{
    private int $maxLevel;

    const MAX_RANK_DIFF = 2;

    public function __construct()
    {
        $config = $this->getConfig();
        $this->maxLevel = max(array_keys($config));
    }

    public function pay(LocaleEnum $currency, Node $nodeInitiator, Node $binaryBonusReceiver, float $binaryBonusAmount, IBonusListener $listener): void
    {
        /** @var Node $sponsor - вышестоящий по структуре лично-приглашенных (ЛП) */
        $sponsor = $binaryBonusReceiver->sponsor;
        $level = 0;
        while ($sponsor) {
            ++$level;
            if ($level > $this->maxLevel) {
                break;
            }
            $this->payForNode($currency, $nodeInitiator, $sponsor, $binaryBonusReceiver, $level, $binaryBonusAmount, $listener);
            $sponsor = $sponsor->sponsor;
        }
    }

    private function payForNode(LocaleEnum $currency, Node $nodeInitiator, Node $receiver, Node $binaryBonusReceiver,
                                int        $level, float $binaryBonusAmount, IBonusListener $listener): void
    {
        if ($this->hasMoreRankInChain($receiver, $binaryBonusReceiver)) {
            return;
        }

        $percent = $this->getPercent($level, $receiver->rank_id, $receiver->package_id);
        if (!$percent) {
            return;
        }

        $bonusAmount = $percent > 0 ? $binaryBonusAmount / 100 * $percent : 0;

        if ($bonusAmount) {
            $receiver->addLeaderBonus(new BonusDataLeader($nodeInitiator->id, $nodeInitiator->name,
                $binaryBonusAmount, $level, $percent, $bonusAmount, $currency->currency));

            $listener->onBonusPay($receiver, $bonusAmount, __("calculator::marketing.bonus_leader", [
                'level' => $level,
                'from_bonus_amount' => $binaryBonusAmount,
                'percent' => $percent,
                'amount' => CurrencyFormatter::fiat($bonusAmount, $currency->currency),
            ]));
        }
    }

    /*
     * $sponsor - всегда выше по ЛП, чем $binaryBonusReceiver
     *
     * Если между $sponsor и тем, кто получил бинарный бонус,
     * есть пользователь, чей ранг выше, чем у $sponsor на 2 и более,
     * то $sponsor не получает лидерский бонус.
     *
     * ТЗ:
     * Спонсор не получает Лидерский бонус от Партнера и его глубины,
     * если разница в статусах между Спонсором и Партнером составляет минус два статуса и более.
     * */
    private function hasMoreRankInChain(Node $sponsor, Node $binaryBonusReceiver): bool
    {
        $chainNode = $binaryBonusReceiver;
        while ($chainNode) {
            $difference = ($chainNode->rank_id ?? 0) - ($sponsor->rank_id ?? 0);

            if ($difference >= self::MAX_RANK_DIFF) {
                return true;
            }
            $chainNode = $chainNode->sponsor;

            if ($chainNode->id == $sponsor->id) {
                break;
            }
        }
        return false;
    }

    /**
     * @return array
     */
    protected function getConfig(): array
    {
        return [
            /*level*/ 1 => [
                /*package*/ 1 => [/*rank*/ 2 => 10, /*rank*/ 3 => 10, /*rank*/ 4 => 10],
                /*package*/ 2 => [/*rank*/ 2 => 15, /*rank*/ 3 => 15, /*rank*/ 4 => 15],
                /*package*/ 3 => [/*rank*/ 2 => 20,  /*rank*/ 3 => 20, /*rank*/ 4 => 20],
            ],
            /*level*/ 2 => [
                /*package*/ 1 => [/*rank*/ 2 => 0, /*rank*/ 3 => 0, /*rank*/ 4 => 0],
                /*package*/ 2 => [/*rank*/ 2 => 0, /*rank*/ 3 => 0, /*rank*/ 4 => 0],
                /*package*/ 3 => [/*rank*/ 2 => 0, /*rank*/ 3 => 0, /*rank*/ 4 => 10],
            ],
        ];
    }

    public function getPercent(int $level, ?int $rankId, ?int $packageId): float
    {
        $config = $this->getConfig();
        return $config[$level][$packageId][$rankId] ?? 0;
    }
}
