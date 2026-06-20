<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Structure\Structure;
use Modules\ConfigIziGo\Enums\LocaleEnum;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;
use Modules\ConfigIziGo\Helpers\Locale as LocaleHelper;
use Modules\ConfigIziGo\Http\Resources\Locale;

/**
 * @mixin Structure
 */
class StructureResource extends JsonResource
{
    public function toArray(Request $request)
    {
        $lang = LocaleHelper::lang();
        $currency = LocaleEnum::create(LocaleHelper::currency());

        $token = CalculatorAuth::token();
        $canEdit = $this->canEdit($token);
        $root = $this->getRoot();

        return [
            'id' => $this->id,
            'auth_email' => $token?->user?->email,
            'auth_user' => resolve(CalculatorUser::class, ['resource' => $token?->user]),
            'created_at' => $this->created_at->format('d.m.Y H:i'),
            'calculator_owner_email' => $this->user->email,
            'calculator_owner_id' => $this->user->id,
            'calculator_owner' => resolve(CalculatorUser::class, ['resource' => $this->user]),
            'lang' => resolve(Locale::class, ['resource' => LocaleEnum::create($lang)]),
            'currency' => resolve(Locale::class, ['resource' => LocaleEnum::create($currency)]),
            'can_edit' => $canEdit,
            'token_view' => $this->token_view,
            'token_edit' => $canEdit ? $this->token_edit : null,
            'root' => resolve(StructureNode::class, ['resource' => $root]),
            'last_added_node' => resolve(StructureNodeLast::class, ['resource' => $this->lastNodeWithPackage]),
            'last_bonus_sum' => $root->last_bonus_sum,
            'last_bonus_sum_format' => CurrencyFormatter::fiat($root->last_bonus_sum, $currency->currency),
            'profit_by_last_node' => $this->getRootProfitByNodeLast(),
            'profit_by_last_node_format' => CurrencyFormatter::fiat($this->getRootProfitByNodeLast(), $currency->currency),
        ];
    }
}
