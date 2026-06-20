<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Structure\Structure;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;
use Modules\ConfigIziGo\Helpers\Locale;

/**
 * @mixin Structure
 */
class StructureLightResource extends JsonResource
{
    public function toArray(Request $request)
    {
        $root = $this->getRoot();
        $currency = LocaleEnum::create(Locale::currency());
        $token = CalculatorAuth::token();

        //ресурс только для просмотра владельцем структур
        return [
            'id' => $this->id,
            'auth_email' => $token?->user?->email,
            'created_at' => $this->created_at->format('d.m.Y H:i'),
            'calculator_owner_email' => $this->user->email,
            'calculator_owner_id' => $this->user->id,
            'can_edit' => true,
            'token_view' => $this->token_view,
            'token_edit' => $this->token_edit,
            'last_added_node' => resolve(StructureNodeLast::class, ['resource' => $this->lastNodeWithPackage]),
            'last_bonus_sum' => $root->last_bonus_sum,
            'last_bonus_sum_format' => CurrencyFormatter::fiat($root->last_bonus_sum, $currency->currency),
            'profit_by_last_node' => $this->getRootProfitByNodeLast(),
            'profit_by_last_node_format' => CurrencyFormatter::fiat($this->getRootProfitByNodeLast(), $currency->currency),
        ];
    }

}
