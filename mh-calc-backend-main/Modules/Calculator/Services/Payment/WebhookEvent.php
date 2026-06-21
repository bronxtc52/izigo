<?php

namespace Modules\Calculator\Services\Payment;

/**
 * Нормализованное событие платёжного webhook (после проверки подписи).
 * status ∈ paid|failed|expired|pending.
 */
class WebhookEvent
{
    public const PAID = 'paid';
    public const FAILED = 'failed';
    public const EXPIRED = 'expired';
    public const PENDING = 'pending';

    public function __construct(
        public readonly string $externalRef, // наш ref ("pay:{paymentId}"), эхо от шлюза
        public readonly string $providerId,
        public readonly string $status,
        public readonly int $amountCents,
        public readonly array $raw = [],
    ) {
    }
}
