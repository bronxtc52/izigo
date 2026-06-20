<?php

namespace Modules\Calculator\Services\Log;

class CalculatorLog
{
    private string $newLine = "\n";

    /**
     * @var ILogItem[]
     */
    private array $eventList = [];

    /**
     * @var INotifyItem[]
     */
    private array $notifyEventList = [];

    public function __construct($newLine = "\n")
    {
        $this->newLine = $newLine;
    }

    public function add(ILogItem $message): void
    {
        $this->eventList[] = $message;
    }

    public function addForNotify(INotifyItem $event): void
    {
        $this->notifyEventList[] = $event;
    }

    public function __toString(): string
    {
        $result = [];
        foreach ($this->eventList as $event) {
            if (!$event->isEmpty()) {
                $result[] = (string)$event;
            }
        }
        $result[] = 'For notify events: ';
        foreach ($this->notifyEventList as $event) {
            $result[] = $event->getMessage();
        }

        return implode($this->newLine, $result);
    }

    public function getEventList(): array
    {
        return $this->eventList;
    }

    public function getNotifyList(): array
    {
        return $this->notifyEventList;
    }
}
