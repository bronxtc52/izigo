<?php

namespace Modules\Calculator\Dto;


use Modules\Calculator\Models\Structure\Structure;
use Spatie\LaravelData\Data;

class NodeUpdateData extends Data
{
    /**
     * Имя - редактируемое поле, по умолчанию содержит значение “Пользователь [ID]”
     * Спонсора - выбирается в выпадающем списке из уже добавленных участников
     * Пакет - выбирается в выпадающем списке из существующих вариантов
     *
     * @param Structure $structure
     * @param int $node_id
     * @param int|null $sponsor_id
     * @param string|null $username
     * @param int|null $package_id
     */
    public function __construct(
        public Structure $structure,
        public int       $node_id,
        public ?int      $sponsor_id,
        public ?string   $username = null,
        public ?int      $package_id = null
    )
    {
    }


}
