<?php

namespace Modules\Calculator\Services\Placement;

use InvalidArgumentException;
use Modules\Calculator\Models\Member;

/**
 * Ручное размещение: спонсор явно выбирает родителя и сторону. Валидация:
 * сторона корректна, родитель в поддереве спонсора, слот свободен.
 */
class ManualStrategy implements PlacementStrategy
{
    public function __construct(
        private readonly PlacementTree $tree,
        private readonly int $parentId,
        private readonly string $position,
    ) {
    }

    public function resolveSlot(Member $sponsor): array
    {
        if (!in_array($this->position, ['left', 'right'], true)) {
            throw new InvalidArgumentException("Недопустимая сторона: {$this->position}");
        }

        $parent = Member::find($this->parentId);
        if ($parent === null) {
            throw new InvalidArgumentException("Родитель {$this->parentId} не найден");
        }

        if (!$this->tree->isDescendantOrSelf($parent, $sponsor)) {
            throw new InvalidArgumentException('Слот вне поддерева спонсора');
        }

        if ($this->tree->childOf($this->parentId, $this->position) !== null) {
            throw new InvalidArgumentException('Слот уже занят');
        }

        return [$this->parentId, $this->position];
    }
}
