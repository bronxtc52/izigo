<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Calculator\Models\Rank
 */
class Rank extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'sort' => $this->sort,
            'name' => $this->name,
        ];
    }

}
