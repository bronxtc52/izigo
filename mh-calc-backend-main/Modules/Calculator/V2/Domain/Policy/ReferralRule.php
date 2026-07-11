<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: параметры реферальной премии (T07). Ставки по уровням/тирам — в {@see TierRule}.
 * stopAtElite — конфиг-флаг, дефолт FALSE (решение владельца 2026-07-12: реферальная
 * платится всегда, независимо от тира/статуса покупателя).
 */
final class ReferralRule
{
    public function __construct(
        public readonly int $maxDepth,
        public readonly bool $stopAtElite,
        public readonly string $destination,
        public readonly string $trigger,
    ) {
    }
}
