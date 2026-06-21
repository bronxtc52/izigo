<?php

namespace Modules\Calculator\Services\Placement;

use Modules\Calculator\Models\Member;

/**
 * Стратегия выбора слота под спонсором. Реализации: авто-спилловер / ручная.
 */
interface PlacementStrategy
{
    /**
     * Найти слот размещения под $sponsor.
     *
     * @return array{0:int,1:string} [parentId, position(left|right)]
     */
    public function resolveSlot(Member $sponsor): array;
}
