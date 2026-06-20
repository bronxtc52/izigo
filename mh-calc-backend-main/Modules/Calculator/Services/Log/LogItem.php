<?php

namespace Modules\Calculator\Services\Log;

class LogItem implements ILogItem
{
    protected array $eventList = [];

    public function __construct(public string $delimiter)
    {
    }

    public function add(string $event): self
    {
        $this->eventList[] = $event;
        return $this;
    }

    public function isEmpty(): bool
    {
        return empty($this->eventList);
    }

    public function __toString(): string
    {
        return implode($this->delimiter, $this->eventList);
    }

    public function toArray(): array
    {
        return $this->eventList;
    }
}
