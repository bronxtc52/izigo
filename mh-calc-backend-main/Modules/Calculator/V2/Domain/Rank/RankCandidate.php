<?php

namespace Modules\Calculator\V2\Domain\Rank;

/**
 * T05: кандидат-квалифаер в реферальном поддереве получателя — вход чистого ядра
 * (без Eloquent). rootBranchMemberId — первый узел после получателя на пути
 * sponsor_id (BR-TREE-001); isLevelOne — кандидат стоит на 1-й линии получателя.
 */
final class RankCandidate
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $rankCode,
        public readonly int $rankOrdinal,
        public readonly bool $isLevelOne,
        public readonly int $rootBranchMemberId,
    ) {
    }
}
