<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: правило одного статуса лестницы — квалификация + денежные параметры бинара.
 * Деньги — integer USD-центы, ставки — integer basis points, PV-пороги — integer
 * (float в money-контуре не появляется, DEC-002).
 *
 * Квалификационные поля заполняются по классу статуса (остальные null):
 *  - CLIENT: personalPurchasePvMin;
 *  - CONSULTANT: qualifiedReferralsMin + referralPvMin;
 *  - MANAGER/BRONZE_MANAGER: smallBranchPvMin + directReferralsMin;
 *  - SILVER_MANAGER+: smallBranchPvMin + anchorRank/supportRank + variants.
 */
final class StatusRule
{
    /**
     * @param QualificationVariantRule[] $variants
     */
    public function __construct(
        public readonly StatusCode $code,
        public readonly int $ordinal,
        public readonly int $binaryRateBp,
        public readonly int $monthlyCapCents,
        public readonly int $halfMonthCapCents,
        public readonly int $eliteLeadershipDepth,
        public readonly ?int $personalPurchasePvMin = null,
        public readonly ?int $qualifiedReferralsMin = null,
        public readonly ?int $referralPvMin = null,
        public readonly ?int $smallBranchPvMin = null,
        public readonly ?int $directReferralsMin = null,
        public readonly ?StatusCode $anchorRank = null,
        public readonly ?StatusCode $supportRank = null,
        public readonly array $variants = [],
    ) {
    }
}
