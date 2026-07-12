<?php

namespace Modules\Calculator\V2\Domain\Rank;

/**
 * T05: корневая реферальная ветвь (BR-TREE-001): для получателя P корневая ветвь
 * кандидата X — ПЕРВЫЙ узел после P на пути sponsor_id от X вверх. Один и тот же
 * корневой реферал даёт ровно одну ветвь независимо от глубины кандидатов.
 * Чистая функция над картой sponsor_id (без Eloquent/БД).
 */
class RootBranchResolver
{
    /**
     * @param array<int, ?int> $sponsorById карта member_id => sponsor_id
     * @return ?int id корневого узла ветви; null — кандидат вне поддерева получателя
     */
    public function rootBranchFor(int $receiverId, int $candidateId, array $sponsorById): ?int
    {
        if ($candidateId === $receiverId) {
            return null; // сам получатель — не кандидат
        }

        $node = $candidateId;
        $seen = [];
        while ($node !== null && !isset($seen[$node])) {
            $seen[$node] = true;
            $sponsor = $sponsorById[$node] ?? null;
            if ($sponsor === $receiverId) {
                return $node; // первый узел после P на пути к P
            }
            $node = $sponsor;
        }

        return null; // вышли в корень (или цикл) — X не в поддереве P
    }
}
