<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Structure\Node;
use Modules\Calculator\Models\Structure\Structure;

class NodeSerializer
{
    public function serialize(?Node $node): ?string
    {
        return json_encode($this->pack($node));
    }

    protected function pack(?Node $node): ?array
    {
        if (empty($node)) return null;

        $clone = clone $node;

        $clone->parent = null;

        $children = [];

        for ($index = 0; $index < Structure::WIDTH; $index++) {
            $children[] = empty($clone->children[$index]) ? null : $this->pack($clone->children[$index]);
        }

        return [
            'id' => $clone->id,
            'name' => $clone->name,
            'pos' => $clone->pos,
            'parent_id' => $clone->parent_id,
            'sponsor_id' => $clone->sponsor_id,
            'invited_count' => $clone->invited_count,
            'package_id' => $clone->package_id,
            'rank_id' => $clone->rank_id,
            'children' => $children
        ];
    }

    public function deserialize(string $data, ?Node $parent): ?Node
    {
        return $this->deserializeRecursive(json_decode($data, true), $parent);
    }

    protected function deserializeRecursive(?array $data, ?Node $parent): ?Node
    {
        if (empty($data)) return null;

        $node = new Node($data['id'], $data['name']);
        $node->name = $data['name'];
        $node->parent_id = $data['parent_id'];
        $node->pos = $data['pos'];
        $node->parent = $parent;
        $node->sponsor_id = $data['sponsor_id'];
        $node->sponsor = $parent?->findSponsor($node->sponsor_id);
        $node->invited_count = $data['invited_count'] ?? 0;
        $node->package_id = $data['package_id'];

        foreach ($data['children'] as $index => $child) {
            $node->children[$index] = $this->deserializeRecursive($child, $node);
        }

        return $node;
    }
}
