<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: параметры лидерского бонуса (T08).
 *  - base = PAID_AFTER_CAPS_AND_POOL (DEC-029: % от фактически выплаченной структурной
 *    премии даунлайна ПОСЛЕ капов и 60%-калибровки);
 *  - rankGapBlockOrdinalDiff (DEC-030, amendments MF-11): «блок без передачи» — узел пути
 *    с ordinal >= receiver_ordinal + diff блокирует себя и всё поддерево, бонус выше НЕ
 *    передаётся; дефолт 3 (Director: Sapphire платится, Diamond+ — блок);
 *  - START/BUSINESS — только L1 (10%/15%); ELITE — глубина по статусу получателя
 *    ({@see StatusRule::$eliteLeadershipDepth}), ставки по уровням 20/10/5/3/1/1/1%.
 */
final class LeadershipRule
{
    /**
     * @param int[] $startRatesBp
     * @param int[] $businessRatesBp
     * @param int[] $eliteRatesBp
     */
    public function __construct(
        public readonly StatusCode $eligibilityStatusMin,
        public readonly string $base,
        public readonly int $rankGapBlockOrdinalDiff,
        public readonly array $startRatesBp,
        public readonly array $businessRatesBp,
        public readonly array $eliteRatesBp,
        public readonly int $eliteMaxDepth,
    ) {
    }
}
