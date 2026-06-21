<?php

namespace Modules\Calculator\Services\Payout;

/**
 * Результат on-chain выплаты. status ∈ broadcast|confirmed|failed.
 */
class PayoutResult
{
    public const BROADCAST = 'broadcast';
    public const CONFIRMED = 'confirmed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $txHash = null,
        public readonly ?string $error = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === self::BROADCAST || $this->status === self::CONFIRMED;
    }
}
