<?php

namespace Modules\Calculator\Dto;


use Spatie\LaravelData\Data;

class UserNodeData extends Data
{

    /**
     * Имя - редактируемое поле, по умолчанию содержит значение “Пользователь [ID]”
     * Спонсора - выбирается в выпадающем списке из уже добавленных участников
     * Пакет - выбирается в выпадающем списке из существующих вариантов
     *
     * @param string|null $username
     * @param int|null $sponsor_id
     * @param int|null $package_id
     */
    public function __construct(
        public ?string $username = null,
        public ?int    $sponsor_id = null,
        public ?int    $package_id = null
    )
    {
    }


}
