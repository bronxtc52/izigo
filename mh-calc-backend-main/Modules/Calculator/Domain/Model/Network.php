<?php

namespace Modules\Calculator\Domain\Model;

use InvalidArgumentException;

/**
 * Бинарная сеть: хранит узлы, связывает placement (parent/children) и sponsorship.
 * Построение из плоского списка определений (для тестов и будущего источника данных).
 */
final class Network
{
    /** @var array<int,MemberNode> id => node */
    private array $nodes = [];
    private ?MemberNode $root = null;

    public function add(MemberNode $node): void
    {
        $this->nodes[$node->id] = $node;
    }

    public function get(int $id): ?MemberNode
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Связать узлы: parent (placement) и sponsor (ЛП) по id.
     * @param array<int,int|null> $parentByChild  childId => parentId (null для корня)
     */
    public function link(array $parentByChild): void
    {
        foreach ($parentByChild as $childId => $parentId) {
            $child = $this->get($childId) ?? throw new InvalidArgumentException("node $childId not found");
            if ($parentId === null) {
                $this->root = $child;
                continue;
            }
            $parent = $this->get($parentId) ?? throw new InvalidArgumentException("parent $parentId not found");
            $child->parent = $parent;
            $parent->children[] = $child;
        }
        foreach ($this->nodes as $node) {
            $node->sponsor = $node->sponsorId ? $this->get($node->sponsorId) : null;
        }
    }

    public function root(): ?MemberNode
    {
        return $this->root;
    }

    /** @return MemberNode[] по возрастанию id (порядок постановки) */
    public function orderedById(): array
    {
        $list = $this->nodes;
        ksort($list);
        return array_values($list);
    }
}
