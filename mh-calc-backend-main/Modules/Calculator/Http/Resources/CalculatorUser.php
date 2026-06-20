<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Calculator\Models\CalculatorUser
 */
class CalculatorUser extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'avatar' => $this->avatar,
        ];
    }

}
