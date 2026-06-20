<?php

namespace Modules\Calculator\Dto;


use Modules\Calculator\Models\Structure\Structure;
use Spatie\LaravelData\Data;

class NodeCreateData extends Data
{
    /**
     * Имя - редактируемое поле, по умолчанию содержит значение “Пользователь [ID]”
     * Спонсора - выбирается в выпадающем списке из уже добавленных участников
     * Пакет - выбирается в выпадающем списке из существующих вариантов
     *
     * @param Structure $structure
     * @param int $top_node_id
     * @param int $position
     * @param int $sponsor_id
     * @param string|null $username
     * @param int|null $package_id
     */
    public function __construct(
        public Structure $structure,
        public int       $top_node_id,
        public int       $position,
        public int       $sponsor_id,
        public ?string   $username = null,
        public ?int      $package_id = null
    )
    {
    }


}
