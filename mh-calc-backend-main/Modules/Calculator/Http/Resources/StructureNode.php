<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calculator\Models\Package as PackageModel;
use Modules\Calculator\Models\Rank as RankModel;
use Modules\Calculator\Models\Structure\Node;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;
use Modules\ConfigIziGo\Helpers\Locale;

/**
 * @mixin Node
 */
class StructureNode extends JsonResource
{
    public function toArray(Request $request)
    {
        $currency = LocaleEnum::create(Locale::currency());

        $package = PackageModel::getById($this->package_id, $currency->value);
        $rank = RankModel::getById($this->rank_id, $currency->value);

        return [
            'id' => $this->id,
            'pos' => $this->pos,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'possible_sponsor_list' => (object)$this->getPossibleSponsors(false),
            'possible_sponsor_list_for_child' => (object)$this->getPossibleSponsors(true),
            'sponsor_id' => $this->sponsor_id,
            'sponsor' => $this->sponsor?->name,
            'package_id' => $this->package_id,
            'package_name' => $package?->name,
            'package_pv' => $package?->volume?->pv,
            'package_bv' => $package?->volume?->bv,
            'rank_id' => $this->rank_id,
            'rank_name' => $rank?->name,
            'invited_count' => $this->invited_count,

            'pv_left' => $this->getBranchPV(0),
            'pv_left_format' => CurrencyFormatter::pv($this->getBranchPV(0)),
            'pv_right' => $this->getBranchPV(1),
            'pv_right_format' => CurrencyFormatter::pv($this->getBranchPV(1)),

            'bv_left' => $this->getBranchBV(0),
            'bv_left_format' => CurrencyFormatter::bv($this->getBranchBV(0)),
            'bv_right' => $this->getBranchBV(1),
            'bv_right_format' => CurrencyFormatter::bv($this->getBranchBV(1)),

            'binary_for_bonus_volume_left' => CurrencyFormatter::bv($this->children[0]->parent_binary_bv ?? 0),
            'binary_for_bonus_volume_right' => CurrencyFormatter::bv($this->children[1]->parent_binary_bv ?? 0),

            'bonus_rank_sum' => $this->bonus_rank_sum,
            'bonus_rank_sum_format' => CurrencyFormatter::fiat($this->bonus_rank_sum, $currency->currency),
            'bonus_binary_sum' => $this->bonus_binary_sum,
            'bonus_binary_sum_format' => CurrencyFormatter::fiat($this->bonus_binary_sum, $currency->currency),

            'bonus_referral_sum' => $this->bonus_referral_sum,
            'bonus_referral_sum_format' => CurrencyFormatter::fiat($this->bonus_referral_sum, $currency->currency),

            'bonus_referral_sum_level_1' => $this->bonus_referral_by_level[1] ?? 0,
            'bonus_referral_sum_level_1_format' => CurrencyFormatter::fiat($this->bonus_referral_by_level[1] ?? 0, $currency->currency),
            'bonus_referral_sum_level_2' => $this->bonus_referral_by_level[2] ?? 0,
            'bonus_referral_sum_level_2_format' => CurrencyFormatter::fiat($this->bonus_referral_by_level[2] ?? 0, $currency->currency),

            'bonus_leader_sum' => $this->bonus_leader_sum,
            'bonus_leader_sum_format' => CurrencyFormatter::fiat($this->bonus_leader_sum, $currency->currency),

            'all_bonus_sum' => $this->all_bonus_sum,
            'all_bonus_sum_format' => CurrencyFormatter::fiat($this->all_bonus_sum, $currency->currency),

            'last_bonus_sum' => $this->last_bonus_sum,
            'last_bonus_sum_format' => CurrencyFormatter::fiat($this->last_bonus_sum, $currency->currency),

            'children' => $this->getChildren()
        ];
    }

    private function getChildren(): array
    {
        $result = [];
        foreach ($this->children as $child) {
            $result[] = resolve(StructureNode::class, ['resource' => $child]);
        }
        return $result;
    }

}
