<?php

namespace Modules\Calculator\Services\Bonus;


use Modules\Calculator\Dto\Resource\Bonus\BonusDataBinary;
use Modules\Calculator\Models\Structure\Node;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class BonusBinaryService
{
    public function pay(LocaleEnum $currency, Node $nodeInitiator, IBonusListener $listener): void
    {
        /** @var Node $parent - вышестоящий по бинару */
        $parent = $nodeInitiator->parent;
        while ($parent) {
            $this->payForNode($parent, $currency, $nodeInitiator, $listener);
            $parent = $parent->parent;
        }
    }

    private function payForNode(Node $receiver, LocaleEnum $currency, Node $nodeInitiator, IBonusListener $listener): void
    {
        $smallBranchVolume = $this->getSmallBranchVolume($receiver);
        if (!$smallBranchVolume) {
            //print "BonusBinaryService from {$nodeInitiator->id} to {$receiver->id} !smallBranchVolume\n";
            return;
        }
        $percent = $this->getPercent($receiver->rank_id);
        $bonusAmount = $percent > 0 ? $smallBranchVolume / 100 * $percent : 0;

        if ($bonusAmount) {
            //print "BonusBinaryService For: {$receiver->name} percent: {$percent} amount: {$bonusAmount}\n";

            $receiver->addBinaryBonus(new BonusDataBinary($nodeInitiator->id, $nodeInitiator->name,
                $smallBranchVolume, $percent, $bonusAmount, $currency->currency));

            $listener->onBonusPay($receiver, $bonusAmount, __("calculator::marketing.bonus_binary", [
                'percent' => $percent,
                'amount' => CurrencyFormatter::fiat($bonusAmount, $currency->currency),
                'small_branch_volume' => CurrencyFormatter::bv($smallBranchVolume)
            ]));

            $this->reduceBranchVolume($receiver, $smallBranchVolume);

            $bonusLeaderService = new BonusLeaderService();
            $bonusLeaderService->pay($currency, $nodeInitiator, $receiver, $bonusAmount, $listener);
        }
    }

    public function getSmallBranchVolume(Node $node): float
    {
        $volumesList = [];
        foreach ($node->children as $child) {
            $volumesList[] = $child->parent_binary_bv ?? 0;
        }
        return empty($volumesList) ? 0 : min($volumesList);
    }

    public function reduceBranchVolume(Node $node, float $volume): void
    {
        foreach ($node->children as $child) {
            $child->parent_binary_bv -= $volume;
        }
    }

    public function getPercent(int $receiverRankId): float
    {
        $config = $this->getConfig();
        foreach ($config as $rankId => $percent) {
            if ($receiverRankId >= $rankId) {
                return $percent;
            }
        }
        return 0;
    }

    public function getConfig(): array
    {
        /** id ранга => проценты от объема малой ветки по бинару */
        return [
            4 => 5,
            3 => 5,
            2 => 5,
            1 => 5,
        ];
    }
}
