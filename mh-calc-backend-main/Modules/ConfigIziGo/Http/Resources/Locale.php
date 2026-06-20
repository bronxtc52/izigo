<?php

namespace Modules\ConfigIziGo\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ConfigIziGo\Enums\LocaleEnum;

/**
 * @mixin LocaleEnum
 */
class Locale extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'code' => $this->value,
            'name' => $this->name,
            'currency' => $this->currency,
            'country' => $this->country,
            'language' => $this->language
        ];
    }

}
