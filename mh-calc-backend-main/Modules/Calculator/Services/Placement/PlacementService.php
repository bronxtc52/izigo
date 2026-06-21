<?php

namespace Modules\Calculator\Services\Placement;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\PlanSetting;

/**
 * Размещение участника в бинар-дереве. Первый участник — корень. Режим компании
 * (auto|manual) берётся из plan_settings. Конкурентная безопасность: блокировка
 * родителя FOR UPDATE + перепроверка слота под локом; финальный гарант —
 * уникальный индекс (parent_id, position).
 */
class PlacementService
{
    public function __construct(private readonly PlacementTree $tree)
    {
    }

    public function place(
        Member $new,
        ?Member $sponsor,
        ?int $manualParentId = null,
        ?string $manualPosition = null,
    ): Member {
        return DB::transaction(function () use ($new, $sponsor, $manualParentId, $manualPosition) {
            $existingRoot = Member::query()
                ->whereNull('parent_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            // Первый участник сети — корень (без родителя/стороны). Единственный
            // случай parent_id=NULL; гарант — partial unique index members_single_root.
            if ($existingRoot === null) {
                $new->parent_id = null;
                $new->position = null;
                $new->save();
                $this->setPath($new, (string) $new->id);
                return $new->refresh();
            }

            $effectiveSponsor = $sponsor ?? $existingRoot;
            // Сериализуем параллельные размещения под этим спонсором.
            Member::query()->where('id', $effectiveSponsor->id)->lockForUpdate()->first();

            $mode = PlanSetting::get('placement_mode', 'auto');
            $strategy = $mode === 'manual'
                ? new ManualStrategy($this->tree, (int) $manualParentId, (string) $manualPosition)
                : new AutoSpilloverStrategy($this->tree);

            [$parentId, $position] = $strategy->resolveSlot($effectiveSponsor);

            // Блокируем родителя и перепроверяем слот под локом.
            $parent = Member::query()->where('id', $parentId)->lockForUpdate()->first();
            if ($parent === null) {
                throw new RuntimeException("Родитель {$parentId} исчез при размещении");
            }
            if ($this->tree->childOf($parentId, $position) !== null) {
                if ($mode === 'manual') {
                    throw new InvalidArgumentException('Слот занят (конкурентная постановка)');
                }
                [$parentId, $position] = (new AutoSpilloverStrategy($this->tree))->resolveSlot($effectiveSponsor);
                $parent = Member::query()->where('id', $parentId)->lockForUpdate()->first();
            }

            $new->parent_id = $parentId;
            $new->position = $position;
            $new->sponsor_id = $effectiveSponsor->id;
            $new->save();
            $this->setPath($new, ($parent->path ? $parent->path . '.' : '') . $new->id);

            return $new->refresh();
        });
    }

    private function setPath(Member $member, string $path): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('UPDATE members SET path = ?::ltree WHERE id = ?', [$path, $member->id]);
            $member->path = $path;
        } else {
            $member->path = $path;
            $member->save();
        }
    }
}
