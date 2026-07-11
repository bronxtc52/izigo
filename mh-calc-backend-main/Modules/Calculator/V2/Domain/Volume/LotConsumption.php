<?php

namespace Modules\Calculator\V2\Domain\Volume;

/**
 * T03: потребление одного лота в результате матчинга (строка будущей
 * v2_pv_lot_allocations). exhausted — лот выпит до нуля (state=exhausted,
 * весь остаток BV потреблён без округления).
 */
final class LotConsumption
{
    public function __construct(
        public readonly int $lotId,
        public readonly string $side,
        public readonly string $pvConsumed,
        public readonly int $bvCentsConsumed,
        public readonly bool $exhausted,
    ) {
    }
}
