<?php

namespace Modules\Calculator\Dto\Resource;

use Spatie\LaravelData\Data;

abstract class EventData extends Data
{
    abstract public function getDetail(): string;
}
