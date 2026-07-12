<?php

namespace Modules\Calculator\V2\Domain\Rank;

/**
 * T05: снапшот входов оценки лестницы для одного участника (CAL-RANK-001 Inputs):
 * малая ветка lifetime PV (decimal-строка из BinaryVolumeReaderInterface),
 * счётчик квалифицированных личных рефералов 1-й линии (DEC-021: активированный
 * партнёр с оплаченным заказом >= 100 PV) и кандидаты поддерева с рангами.
 */
final class QualificationInput
{
    /**
     * @param RankCandidate[] $candidates кандидаты реферального поддерева (без самого участника)
     */
    public function __construct(
        public readonly int $memberId,
        public readonly int $currentRankOrdinal, // -1 = ранга нет вообще
        public readonly string $smallBranchPv,   // decimal(18,6) строкой
        public readonly int $qualifiedL1Referrals,
        public readonly array $candidates,
    ) {
    }
}
