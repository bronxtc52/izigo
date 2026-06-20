<?php

namespace Modules\Calculator\Services\Log;

interface INotifyItem
{
    public function isGoodEvent():bool;
    public function getMessage():string;

    public function toArray(): array;
}
