<?php

namespace Modules\Calculator\V2\Domain\Rank;

/**
 * T05: детерминированное назначение кандидатов на слоты варианта квалификации
 * (DEC-023/DEC-024): кандидат не используется дважды; при distinct_root_branches
 * все слоты — из попарно различных корневых ветвей (пример Директора PPTX:S38).
 */
final class RankAssignment
{
    public const SLOT_ANCHOR = 'anchor';
    public const SLOT_SUPPORT = 'support';

    /**
     * @param array<int, array{qualifier_partner_id:int, root_branch_member_id:int,
     *                          rank_code_as_of:string, slot:string}> $slots
     */
    public function __construct(public readonly array $slots)
    {
    }
}
