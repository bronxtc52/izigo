<?php

namespace Modules\Calculator\Services\Log;

class LogInitiator extends LogItem implements ILogItem
{
    public function __construct(public int $initiatorId, public string $initiatorName, public string $delimiter)
    {
        parent::__construct($delimiter, false);
    }

    public function __toString(): string
    {
        $result = $this->toArray();
        $result['events'] = implode($this->delimiter, $result['events']);
        return implode($this->delimiter, $result);
    }

    public function toArray(): array
    {
        return [
            'title' => __("calculator::marketing.log.title"),
            'initiator_info' => "[{$this->initiatorId}, {$this->initiatorName}]",
            'initiator_name' => $this->initiatorName,
            'congratulation' => __("calculator::marketing.log.congratulation"),
            'events_start' => __("calculator::marketing.log.initiator_name", ['name' => $this->initiatorName]),
            'events' => parent::toArray(),
            'events_finish' => __("calculator::marketing.log.great_" . mt_rand(1, 3))
        ];
    }

}
