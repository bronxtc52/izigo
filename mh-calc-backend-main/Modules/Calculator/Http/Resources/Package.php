<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ConfigIziGo\Helpers\CurrencyFormatter;

/**
 * @mixin \Modules\Calculator\Models\Package
 */
class Package extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'sort' => $this->sort,
            'name' => $this->name,
            'description' => $this->description,
            'pv' => $this->volume->pv,
            'pv_format' => CurrencyFormatter::pv($this->volume->pv),
            'bv' => $this->volume->bv,
            'bv_format' => CurrencyFormatter::bv($this->volume->bv)
        ];
    }

}
