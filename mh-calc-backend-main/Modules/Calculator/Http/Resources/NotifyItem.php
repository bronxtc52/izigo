<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calculator\Services\Log\INotifyItem;

/**
 * @mixin INotifyItem
 */
class NotifyItem extends JsonResource
{
    public function toArray(Request $request)
    {
        return $this->resource->toArray();
    }
}
