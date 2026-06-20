<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Rank;
use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Services\Bonus\BonusBinaryService;
use Modules\Calculator\Services\Bonus\BonusRankService;
use Modules\Calculator\Services\Bonus\BonusReferralService;
use Modules\Calculator\Services\Bonus\IBonusListener;
use Modules\Calculator\Services\Log\CalculatorLog;
use Modules\Calculator\Services\Log\LogInitiator;
use Modules\Calculator\Services\Log\NotifyItemNewRank;
use Modules\Calculator\Services\Rank\IRankListener;
use Modules\Calculator\Services\Rank\RankService;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

class CalculatorService implements IRankListener, IBonusListener
{
    private LocaleEnum $currency;

    private string $newLine = "\n";

    private array $nodeMap;
    private Structure $structure;
    private CalculatorLog $log;

    private RankService $rankService;
    private BonusRankService $bonusRankService;
    private BonusReferralService $bonusReferralService;
    private BonusBinaryService $bonusBinaryService;
    private ?LogInitiator $byInitiatorLog = null;

    public int $beforeCalculateRank = 0;
    public int $afterCalculateRank = 0;


    public function __construct(string $locale, Structure $structure, $newLine = "\n")
    {
        $this->currency = LocaleEnum::create($locale);
        $this->log = new CalculatorLog($this->currency, $newLine);
        $this->rankService = new RankService();
        $this->bonusRankService = new BonusRankService();
        $this->bonusReferralService = new BonusReferralService();
        $this->bonusBinaryService = new BonusBinaryService();

        $this->structure = $structure;
        $this->newLine = $newLine;
        $this->afterCalculateRank = $this->beforeCalculateRank = (int)$structure->root_last_rank;
    }

    public function calculate(): void
    {
        $this->initNodeMap();

        /** @var Node $node */
        foreach ($this->nodeMap as $node) {

            $this->byInitiatorLog = new LogInitiator($node->id, $node->name, ' ');

            $package = $node->package($this->currency->value);

            $this->onNewNode($node, $node, $node->parent, $package);
            $this->volumes($node, $node, $node, $package);
            $this->ranks($node);
            $this->bonuses($node);

            if (!$this->byInitiatorLog->isEmpty()) {
                $this->log->add($this->byInitiatorLog);
            }
        }

        $this->afterCalculate();
    }

    private function afterCalculate():void
    {
        $root = $this->structure->getRoot();
        $this->afterCalculateRank = $root->rank_id;
        if ($this->beforeCalculateRank != $this->afterCalculateRank)
        {
            $this->structure->root_last_rank = $this->afterCalculateRank;
            $this->structure->save();

            $this->log->addForNotify(new NotifyItemNewRank($this->beforeCalculateRank, $this->afterCalculateRank, $this->currency->value));
        }
    }

    /**
     * Добавление в лог события постановки нижестоящего пользователя
     *
     * @param Node $addedNode
     * @param Node $branchTop
     * @param Node|null $parent
     * @param Package|null $package
     */
    private function onNewNode(Node $addedNode, Node $branchTop, ?Node $parent, ?Package $package): void
    {
        $this->structure->setLastNodeWithPackage($addedNode);

        if (!$parent || !$package) {
            return;
        }

        $parent->onNewNode($addedNode, $branchTop, $package);

        if ($parent->parent) {
            $this->onNewNode($addedNode, $parent, $parent->parent, $package);
        }
    }

    /**
     * Начисление объемов пользователю и его вышестоящим.
     *
     * @param Node $nodeInitiator
     * @param Node $initiatorBranchTop
     * @param Node $node
     * @param Package|null $package
     */
    private function volumes(Node $nodeInitiator, Node $initiatorBranchTop, Node $node, ?Package $package): void
    {
        if (!$package) {
            return;
        }

        $node->parent_binary_bv += $package->volume->bv;
        $node->parent_binary_bv_total += $package->volume->bv;
        $node->parent_binary_total_pv += $package->volume->pv;

        if ($nodeInitiator->id == $node->id) {
            $node->bv_personal += $package->volume->bv;
            $node->pv_personal += $package->volume->pv;

            $node->addPersonalVolume($package->volume->bv, $package->volume->pv);

        } else {
            $node->bv_group += $package->volume->bv;
            $node->pv_group += $package->volume->pv;

            $node->addGroupVolume($package->volume->bv, $package->volume->pv, $initiatorBranchTop, $nodeInitiator);

            if ($node->iRoot()) {
                $this->byInitiatorLog->add(__("calculator::marketing.add_volumes", [
                    'pv_amount' => CurrencyFormatter::pv($package->volume->pv),
                    'bv_amount' => CurrencyFormatter::bv($package->volume->bv),
                    'branch' => $node->getBranchTitle($initiatorBranchTop->id, 'branch_to_')
                ]));
            }
        }

        if ($node->parent) {
            $this->volumes($nodeInitiator, $node, $node->parent, $package);
        }
    }

    private function ranks(Node $node): void
    {
        $this->rankService->checkWithSponsors($node->id, $node, $this, $this->currency->value);
    }

    private function bonuses(Node $node): void
    {
        $this->bonusReferralService->pay($this->currency, $node, $this);
        $this->bonusBinaryService->pay($this->currency, $node, $this);
    }

    private function initNodeMap(): void
    {
        $this->nodeMap = [];
        $this->initNodeMapRecursive($this->structure->getRoot());
        ksort($this->nodeMap);
    }

    private function initNodeMapRecursive(?Node $node): void
    {
        if ($node) {
            $this->nodeMap[$node->id] = $node;
            foreach ($node->children as $child) {
                $this->initNodeMapRecursive($child);
            }
        }
    }

    public function onNewRank(Node $node, Rank $rank): void
    {
        if ($node->iRoot()) {
            $this->byInitiatorLog->add(__('calculator::marketing.new_rank', [
                'rank' => $rank->name
            ]));
        }

        $this->bonusRankService->pay($this->currency, $node, $rank, $this);
    }

    public function onBonusPay(Node $node, $bonusAmount, string $message): void
    {
        if ($node->iRoot()) {
            $this->byInitiatorLog->add($message);
            $this->structure->addRootProfit($bonusAmount);
        }
    }

    public function getLog(): CalculatorLog
    {
        return $this->log;
    }
}
