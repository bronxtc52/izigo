<?php

namespace Modules\Calculator\Dto\Resource;

use Spatie\LaravelData\Data;

class NodeLogEvents
{
    /** @var Data[] */
    public array $eventList = [];
    public int $index = 0;

    public function add(EventData $data, int $indexIncrement = 0):void
    {
        $this->index += $indexIncrement;

        if (!isset($this->eventList[$this->index]))
        {
            $this->eventList[$this->index] = [];
        }

        $this->eventList[$this->index][] = $data;
    }
}
