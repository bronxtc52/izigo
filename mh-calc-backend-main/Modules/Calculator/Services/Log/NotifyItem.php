<?php

namespace Modules\Calculator\Services\Log;

class NotifyItem implements INotifyItem
{
    public function __construct(public bool $goodEvent = true, public string $message = '')
    {
    }

    public function isGoodEvent(): bool
    {
        return $this->goodEvent;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'is_good' => $this->goodEvent,
            'message' => $this->message,
        ];
    }
}
