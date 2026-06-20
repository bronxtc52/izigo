<?php

namespace Modules\Calculator\Domain\Plan;

use Modules\Calculator\Domain\ValueObject\Money;
use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Ранг и условия квалификации. Порог = 0 означает «условие не проверяется».
 * personalInRankId/Count: требуется N лично приглашённых с рангом >= personalInRankId.
 */
final class Rank
{
    public function __construct(
        public readonly int $id,
        public readonly int $sort,
        public readonly string $alias,
        public readonly Pv $smallBranchVolume,
        public readonly int $personalCount,
        public readonly int $personalInRankCount,
        public readonly int $personalInRankId,
        public readonly Money $bonus,
    ) {
    }
}
