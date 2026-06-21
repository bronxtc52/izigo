<?php

namespace Modules\Calculator\Services\Placement;

use Modules\Calculator\Models\Member;

/**
 * Авто-спилловер в слабую ногу: если у спонсора свободна нога — туда; иначе
 * спускаемся в поддерево с меньшим числом узлов и ищем первый свободный слот BFS.
 */
class AutoSpilloverStrategy implements PlacementStrategy
{
    public function __construct(private readonly PlacementTree $tree)
    {
    }

    public function resolveSlot(Member $sponsor): array
    {
        $left = $this->tree->childOf($sponsor->id, 'left');
        if ($left === null) {
            return [$sponsor->id, 'left'];
        }
        $right = $this->tree->childOf($sponsor->id, 'right');
        if ($right === null) {
            return [$sponsor->id, 'right'];
        }

        // Обе ноги заняты — спускаемся в слабую (меньше узлов).
        $start = $this->tree->subtreeCount($left) <= $this->tree->subtreeCount($right)
            ? $left
            : $right;

        return $this->tree->firstFreeSlot($start);
    }
}
