<?php

namespace Modules\Calculator\V2\Domain\Volume;

/**
 * T03: результат чистого матчинга. matchedBvCents = min(BV потреблённого слева,
 * BV потреблённого справа) — BV следует за фактически потреблёнными лотами
 * (DEC-016); при одинаковой BV-плотности сторон совпадает с любой из них.
 * zeroReason — объяснение нулевого матча ('left_empty'|'right_empty'|'both_empty').
 *
 * @property LotConsumption[] $consumptions
 */
final class MatchResult
{
    /** @param LotConsumption[] $consumptions */
    public function __construct(
        public readonly string $matchedPv,
        public readonly int $matchedBvCents,
        public readonly int $leftBvCentsConsumed,
        public readonly int $rightBvCentsConsumed,
        public readonly array $consumptions,
        public readonly ?string $zeroReason = null,
    ) {
    }

    public function isZero(): bool
    {
        return bccomp($this->matchedPv, '0', 6) === 0;
    }
}
