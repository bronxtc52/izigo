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
class StructureNodeLast extends JsonResource
{
    public function toArray(Request $request)
    {
        $currency = LocaleEnum::create(Locale::currency());

        $package = PackageModel::getById($this->package_id, $currency->value);
        $rank = RankModel::getById($this->rank_id, $currency->value);

        $purchaseAmount = $package?->volume?->bv ?? 0;

        return [
            'id' => $this->id,
            'pos' => $this->pos,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'sponsor_id' => $this->sponsor_id,
            'sponsor' => $this->sponsor?->name,
            'package_id' => $this->package_id,
            'package_name' => $package?->name,
            'package_pv' => $package?->volume?->pv,
            'package_bv' => $package?->volume?->bv,
            'rank_id' => $this->rank_id,
            'rank_name' => $rank?->name,

            'purchase_amount' => $purchaseAmount,
            'purchase_amount_format' => CurrencyFormatter::fiat($purchaseAmount, $currency->currency)
        ];
    }

}
