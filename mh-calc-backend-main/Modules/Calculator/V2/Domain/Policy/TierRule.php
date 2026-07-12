<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: тир контракта START/BUSINESS/ELITE по накопленному personal PV
 * (границы [minPv, maxPvExclusive), null = без верхней границы) + реферальные
 * ставки получателя этого тира в basis points (потребитель — T07).
 */
final class TierRule
{
    public function __construct(
        public readonly string $code,
        public readonly int $minPv,
        public readonly ?int $maxPvExclusive,
        public readonly int $referralL1Bp,
        public readonly int $referralL2Bp,
    ) {
    }
}
