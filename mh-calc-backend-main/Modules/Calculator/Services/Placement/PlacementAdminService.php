<?php

namespace Modules\Calculator\Services\Placement;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\AuditLogService;

/**
 * B2: ручной перенос участника в бинар-дереве администратором (owner-only на маршруте).
 *
 * ДВИЖОК НЕ ТРОГАЕМ — меняется только placement (parent_id/position/path), который является
 * ВХОДОМ движка; пересчёт бонусов произойдёт на следующем событии активации (snapshot rebuild).
 * Обязательны: dry-run preview без мутаций, валидация (не-корень, анти-цикл, занятость слота),
 * транзакция с локами (+ финальный гарант — unique index (parent_id, position)) и аудит before/after.
 */
class PlacementAdminService
{
    public function __construct(
        private readonly PlacementTree $tree,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Dry-run: проверка переноса БЕЗ изменений. valid + причина + before/after + объём поддерева. */
    public function preview(int $memberId, int $newParentId, string $position): array
    {
        $member = Member::find($memberId);
        $newParent = Member::find($newParentId);
        [$valid, $reason] = $this->validate($member, $newParent, $position);

        $newPath = ($valid && $member && $newParent)
            ? (($newParent->path ? $newParent->path . '.' : '') . $member->id)
            : null;

        return [
            'valid' => $valid,
            'reason' => $reason,
            'member' => $member ? [
                'id' => $member->id,
                'name' => $member->name,
                'parent_id' => $member->parent_id,
                'position' => $member->position,
                'path' => (string) $member->path,
            ] : null,
            'target' => ['parent_id' => $newParentId, 'position' => $position],
            // Сколько узлов сменят path (включая самого участника) — масштаб операции.
            'affected_nodes' => ($valid && $member) ? $this->tree->subtreeCount($member) : 0,
            'after' => $newPath !== null
                ? ['parent_id' => $newParentId, 'position' => $position, 'path' => $newPath]
                : null,
        ];
    }

    /** Применить перенос. Транзакция + локи + аудит. Бросает InvalidArgumentException (→422) при невалидном. */
    public function move(int $actorId, int $memberId, int $newParentId, string $position): array
    {
        return DB::transaction(function () use ($actorId, $memberId, $newParentId, $position) {
            $member = Member::query()->where('id', $memberId)->lockForUpdate()->first();
            $newParent = Member::query()->where('id', $newParentId)->lockForUpdate()->first();

            [$valid, $reason] = $this->validate($member, $newParent, $position);
            if (!$valid) {
                throw new InvalidArgumentException($reason);
            }

            $before = [
                'parent_id' => $member->parent_id,
                'position' => $member->position,
                'path' => (string) $member->path,
            ];

            $member->parent_id = $newParentId;
            $member->position = $position;
            $member->save(); // unique(parent_id, position) — финальный гарант от гонки за слот

            // Ре-path участника и всего его поддерева (driver-safe BFS по parent_id).
            $this->repath($member, (string) $newParent->path);
            $member->refresh();

            $after = [
                'parent_id' => $member->parent_id,
                'position' => $member->position,
                'path' => (string) $member->path,
                'affected_nodes' => $this->tree->subtreeCount($member),
            ];

            $this->audit->record($actorId, 'placement.move', 'member', $memberId, $before, $after);

            return ['before' => $before, 'after' => $after];
        });
    }

    /** Общая валидация переноса (для preview и move). @return array{0:bool,1:?string} */
    private function validate(?Member $member, ?Member $newParent, string $position): array
    {
        if (!in_array($position, ['left', 'right'], true)) {
            return [false, 'Позиция должна быть left или right'];
        }
        if ($member === null) {
            return [false, 'Участник не найден'];
        }
        if ($member->parent_id === null) {
            return [false, 'Нельзя переносить корень сети'];
        }
        if ($newParent === null) {
            return [false, 'Новый родитель не найден'];
        }
        if ($member->parent_id === $newParent->id && $member->position === $position) {
            return [false, 'Участник уже на этой позиции'];
        }
        // Анти-цикл: новый родитель не может быть самим участником или его потомком.
        if ($this->tree->isDescendantOrSelf($newParent, $member)) {
            return [false, 'Нельзя переносить участника под него самого или его поддерево'];
        }
        // Целевой слот должен быть свободен (или занят самим участником — тогда это no-op, отсечён выше).
        $occupant = $this->tree->childOf($newParent->id, $position);
        if ($occupant !== null && $occupant->id !== $member->id) {
            return [false, 'Слот занят другим участником'];
        }

        return [true, null];
    }

    /** Рекурсивно пересчитать path для узла и его детей (parent_id BFS, driver-safe). */
    private function repath(Member $node, string $parentPath): void
    {
        $newPath = $parentPath === '' ? (string) $node->id : $parentPath . '.' . $node->id;
        $this->writePath($node->id, $newPath);

        $children = Member::query()->where('parent_id', $node->id)->get(['id', 'parent_id']);
        foreach ($children as $child) {
            $this->repath($child, $newPath);
        }
    }

    private function writePath(int $memberId, string $path): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('UPDATE members SET path = ?::ltree WHERE id = ?', [$path, $memberId]);
        } else {
            DB::table('members')->where('id', $memberId)->update(['path' => $path]);
        }
    }
}
