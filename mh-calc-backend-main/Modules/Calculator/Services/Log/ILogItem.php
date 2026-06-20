<?php

namespace Modules\Calculator\Services\Log;

interface ILogItem
{
    public function add(string $event): ILogItem;

    public function isEmpty(): bool;

    public function __toString(): string;

    public function toArray(): array;
}
