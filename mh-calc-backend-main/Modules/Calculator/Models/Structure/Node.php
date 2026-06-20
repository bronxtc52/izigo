<?php

namespace Modules\Calculator\Models\Structure;

use Modules\Calculator\Dto\RankTempData;
use Modules\Calculator\Dto\Resource\Bonus\BonusDataBinary;
use Modules\Calculator\Dto\Resource\Bonus\BonusDataLeader;
use Modules\Calculator\Dto\Resource\Bonus\BonusDataRank;
use Modules\Calculator\Dto\Resource\Bonus\BonusDataReferral;
use Modules\Calculator\Dto\Resource\EventData;
use Modules\Calculator\Dto\Resource\GroupVolume;
use Modules\Calculator\Dto\Resource\NodeAdd;
use Modules\Calculator\Dto\Resource\NodeLogEvents;
use Modules\Calculator\Dto\Resource\NodeRank;
use Modules\Calculator\Dto\Resource\PersonalVolume;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Rank;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;


class Node
{
    public int $id;
    public int $pos = 0;

    public string $name;

    public int $sponsor_id = 0;

    public int $invited_count = 0;

    public ?int $package_id = 0;
    public int $parent_id = 0;

    public ?Node $parent = null;

    public ?Node $sponsor = null;

    /** @var Node[] */
    public array $children = [];

    public int $rank_id = 0;

    public float $pv_personal = 0.0;

    /**
     * Чтобы определить pv ветки, мы берем pv_group + pv_personal главы ветки
     * @var float
     */
    public float $pv_group = 0.0;

    public float $bv_personal = 0.0;

    /**
     * Бинарный объем в какой-то ветке родителя по бинару.
     * Сокращается по бинарному бонусу.
     *
     * @var float
     */
    public float $parent_binary_bv = 0.0;

    /**
     * Бинарный объем в какой-то ветке родителя по бинару.
     * Копится за все время.
     *
     * @var float
     */
    public float $parent_binary_bv_total = 0.0;

    /**
     * Бинарный объем в какой-то ветке родителя по бинару.
     * Копится за все время. Используется для расчетов ранга.
     *
     * @var float
     */
    public float $parent_binary_total_pv = 0.0;

    /**
     * Чтобы определить bv ветки, мы берем bv_group + bv_personal главы ветки
     * @var float
     */
    public float $bv_group = 0.0;

    public float $all_bonus_sum = 0;
    public float $last_bonus_sum = 0;

    public array $bonus_rank_list = [];
    public float $bonus_rank_sum = 0;

    public array $bonus_referral_list = [];
    public array $bonus_referral_by_level = [];
    public float $bonus_referral_sum = 0;

    public array $bonus_binary_list = [];
    public float $bonus_binary_sum = 0;

    public array $bonus_leader_list = [];
    public float $bonus_leader_sum = 0;

    public array $rank_list = [];

    public ?NodeLogEvents $logEvents = null;

    public function package(string $locale): ?Package
    {
        return Package::getById($this->package_id, $locale);
    }

    public function rank(string $locale): ?Rank
    {
        return Rank::getById($this->rank_id, $locale);
    }

    public function __construct(int $id, string $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function childCount(): int
    {
        $result = 0;
        array_map(function ($item) use (&$result) {
            $result += $item ? 1 : 0;
        }, $this->children);
        return $result;
    }

    public function isBusyUsername(string $username, int $excludeNodeId): bool
    {
        if ($this->id != $excludeNodeId && $this->name == $username) {
            return true;
        }

        foreach ($this->children as $node) {
            if ($node && $node->isBusyUsername($username, $excludeNodeId)) {
                return true;
            }
        }

        return false;
    }

    public function iRoot(): bool
    {
        return $this->parent_id == 0;
    }

    public function getBranchIndex(int $childId): string
    {
        foreach ($this->children as $index => $child) {
            if ($child?->id == $childId) {
                return $index + 1;
            }
        }

        return -1;
    }

    public function getBranchTitle(int $childId, string $prefix): string
    {
        foreach ($this->children as $index => $child) {
            if ($child?->id == $childId) {
                return __("calculator::structure.$prefix" . ($index + 1));
            }
        }

        return '';
    }

    public function addRankBonus(BonusDataRank $bonus): void
    {
        $this->bonus_rank_list[$bonus->rank_id] = $bonus;
        $this->bonus_rank_sum += $bonus->amount;
        $this->all_bonus_sum += $bonus->amount;
        $this->last_bonus_sum = $bonus->amount;

        $this->addEvent($bonus);
    }

    private function addEvent(EventData $data, int $indexIncrement = 0):void
    {
        if (!$this->logEvents)
        {
            $this->logEvents = new NodeLogEvents();
        }
        $this->logEvents->add($data, $indexIncrement);
    }

    public function addReferralBonus(BonusDataReferral $bonus): void
    {
        $this->bonus_referral_list[] = $bonus;
        $this->bonus_referral_by_level[$bonus->level] = ($this->bonus_referral_by_level[$bonus->level] ?? 0) + $bonus->amount;
        $this->bonus_referral_sum += $bonus->amount;
        $this->all_bonus_sum += $bonus->amount;
        $this->last_bonus_sum = $bonus->amount;

        $this->addEvent($bonus);
    }

    public function getReferralBonusSumByLevel(int $level): float
    {
        return $this->bonus_referral_by_level[$level] ?? 0;
    }

    public function getReferralBonusByLevels(string $currency): array
    {
        $result = [];
        foreach ($this->bonus_referral_by_level as $level => $list) {
            $result[$level] = CurrencyFormatter::fiat($this->getReferralBonusSumByLevel($level), $currency);
        }
        return $result;
    }

    public function addBinaryBonus(BonusDataBinary $bonus): void
    {
        $this->bonus_binary_list[] = $bonus;
        $this->bonus_binary_sum += $bonus->amount;
        $this->all_bonus_sum += $bonus->amount;
        $this->last_bonus_sum = $bonus->amount;

        $this->addEvent($bonus);
    }

    public function addLeaderBonus(BonusDataLeader $bonus): void
    {
        $this->bonus_leader_list[] = $bonus;
        $this->bonus_leader_sum += $bonus->amount;
        $this->all_bonus_sum += $bonus->amount;
        $this->last_bonus_sum = $bonus->amount;

        $this->addEvent($bonus);
    }

    /**
     * @param Node $addedNode
     * @param Node $branchTop
     * @param Package|null $package
     * @return void
     */
    public function onNewNode(Node $addedNode, Node $branchTop, ?Package $package): void
    {
        $this->addEvent(NodeAdd::from([
            'branch_index' => $this->getBranchIndex($branchTop->id),
            'branch_title' => $this->getBranchTitle($branchTop->id, 'branch_to_'),
            'node_id' => $addedNode->id,
            'node_name' => $addedNode->name,
            'package' => $package->name ?? '-',
        ]), true);
    }

    public function setRank(int $rankId, string $rankName): void
    {
        $this->rank_id = $rankId;
        $this->rank_list[$rankId] = $rankName;

        $this->addEvent(NodeRank::from([
            'rank_id' => $rankId,
            'rank_name' => $rankName
        ]));
    }

    public function addPersonalVolume(float $bv, float $pv): void
    {
        $this->addEvent(PersonalVolume::from([
            'bv' => $bv,
            'pv' => $pv
        ]));
    }

    public function addGroupVolume(float $bv, float $pv, Node $initiatorBranchTop, Node $nodeInitiator): void
    {
        $this->addEvent(GroupVolume::from([
            'bv' => $bv,
            'pv' => $pv,
            'branch_index' => $this->getBranchIndex($initiatorBranchTop->id),
            'branch_title' => $this->getBranchTitle($initiatorBranchTop->id, 'branch_to_'),
            'initiator_id' => $nodeInitiator->id,
            'initiator_name' => $nodeInitiator->name
        ]));
    }

    public function getBranchPV(int $branch): float
    {
        return (($this->children[$branch]->pv_group ?? 0) + ($this->children[$branch]->pv_personal ?? 0));
    }

    public function getBranchBV(int $branch): float
    {
        return (($this->children[$branch]->bv_group ?? 0) + ($this->children[$branch]->bv_personal ?? 0));
    }

    public function findSponsor(?int $sponsorId): ?self
    {
        if ($sponsorId && $this->id == $sponsorId) {
            return $this;
        }
        return $this->parent?->findSponsor($sponsorId) ?? null;
    }

    public function getPossibleSponsors(bool $withOwn): array
    {
        $result = $withOwn ? [$this->id => $this->name] : [];
        $parent = $this->parent;
        while ($parent) {
            $result[$parent->id] = $parent->name;
            $parent = $parent->parent;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }


}
