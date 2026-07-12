<?php

namespace Modules\Calculator\V2\Domain\Volume;

/**
 * T03: срез свободного лота на входе LotMatcher (без Eloquent — чистый домен).
 * PV — decimal-строки (bcmath, scale 6); BV — integer USD-центы.
 * bvCentsRemaining = bv_usd_cents_original минус уже потреблённое прошлыми
 * матчами: гарантирует, что за жизнь лота суммарные BV-аллокации сойдутся
 * ровно в bv_original (ни цента не теряется и не задваивается).
 */
final class LotSlice
{
    public function __construct(
        public readonly int $lotId,
        public readonly string $pvAvailable,
        public readonly int $bvCentsRemaining,
    ) {
    }
}
