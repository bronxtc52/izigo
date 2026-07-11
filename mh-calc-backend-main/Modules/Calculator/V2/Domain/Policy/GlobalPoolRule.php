<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: параметры глобального бонуса (T09): пулы Director..VP (ставка bp от месячного
 * global BV + база PV одной доли), max долей 2 (DEC-032), кап 25% пула на участника,
 * остаток — компании UNALLOCATED (DEC-034), накопление MONTH / выплата QUARTER (DEC-036).
 */
final class GlobalPoolRule
{
    /**
     * @param array<string, array{rate_bp: int, one_share_pv_min: int}> $pools ключ — код статуса
     */
    public function __construct(
        public readonly array $pools,
        public readonly int $maxShares,
        public readonly int $memberCapBp,
        public readonly string $remainder,
        public readonly string $accrual,
        public readonly string $payout,
        public readonly string $quarterMode,
        public readonly bool $inheritsLowerPools,
        public readonly bool $includePersonalPv,
    ) {
    }

    /** Суммарная ставка всех пулов, bp (валидатор требует ровно 300 = 3%). */
    public function totalRateBp(): int
    {
        return array_sum(array_column($this->pools, 'rate_bp'));
    }
}
