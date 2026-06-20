<?php

namespace Modules\Calculator\Domain\Result;

use Modules\Calculator\Domain\ValueObject\Money;

/** Одно начисление бонуса (тип, получатель, сумма, контекст). Иммутабельно. */
final class BonusLine
{
    public const BINARY = 'binary';
    public const REFERRAL = 'referral';
    public const LEADER = 'leader';
    public const RANK = 'rank';

    public function __construct(
        public readonly string $type,
        public readonly int $recipientId,
        public readonly Money $amount,
        public readonly ?int $sourceId = null,
        public readonly ?int $level = null,
        public readonly array $meta = [],
    ) {
    }
}
