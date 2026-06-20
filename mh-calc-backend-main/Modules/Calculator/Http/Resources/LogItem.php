<?php

namespace Modules\Calculator\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Calculator\Services\Log\ILogItem;

/**
 * @mixin ILogItem
 */
class LogItem extends JsonResource
{
    public function toArray(Request $request)
    {
        return $this->resource->toArray();

        //return [
        //    "title": "Постановка пользователя",
        //    "initiator_info": "[2, Пользователь 2]",
        //    "initiator_name": "Пользователь 2",
        //    "congratulation": "Поздравляем! 🎉",
        //    "initiator_prefix": "При постановке Пользователь 2:",
        //    "events": [
        //        "Вам добавлено +200.00 PV и +13 500.00 BV в левую ветку.",
        //        "Реферальный бонус 10% с уровня 1 в размере 1 350.00 RUB!"
        //    ],
        //    "great": "Отличный результат!"
        //];
    }

}
