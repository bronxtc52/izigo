<?php

namespace Modules\Calculator\Services\Placement;

use Modules\Calculator\Models\Member;

/**
 * Запросы по бинар-дереву для стратегий размещения (driver-agnostic, через parent_id).
 */
class PlacementTree
{
    public function childOf(int $parentId, string $position): ?Member
    {
        return Member::query()
            ->where('parent_id', $parentId)
            ->where('position', $position)
            ->first();
    }

    /** Кол-во узлов в поддереве (включая корень поддерева), BFS по parent_id. */
    public function subtreeCount(Member $node): int
    {
        $count = 0;
        $queue = [$node->id];
        while ($queue !== []) {
            $id = array_shift($queue);
            $count++;
            $childIds = Member::query()->where('parent_id', $id)->pluck('id')->all();
            foreach ($childIds as $childId) {
                $queue[] = (int) $childId;
            }
        }
        return $count;
    }

    /** Первый свободный слот вниз от $start, breadth-first (left раньше right). @return array{0:int,1:string} */
    public function firstFreeSlot(Member $start): array
    {
        $queue = [$start];
        while ($queue !== []) {
            /** @var Member $node */
            $node = array_shift($queue);
            $left = $this->childOf($node->id, 'left');
            if ($left === null) {
                return [$node->id, 'left'];
            }
            $right = $this->childOf($node->id, 'right');
            if ($right === null) {
                return [$node->id, 'right'];
            }
            $queue[] = $left;
            $queue[] = $right;
        }
        // недостижимо: дерево конечно, всегда есть свободный слот на листе
        return [$start->id, 'left'];
    }

    /** $node находится в поддереве $ancestor (или равен ему) — подъём по parent_id. */
    public function isDescendantOrSelf(Member $node, Member $ancestor): bool
    {
        $cursor = $node;
        while ($cursor !== null) {
            if ($cursor->id === $ancestor->id) {
                return true;
            }
            $cursor = $cursor->parent_id !== null ? Member::find($cursor->parent_id) : null;
        }
        return false;
    }
}
