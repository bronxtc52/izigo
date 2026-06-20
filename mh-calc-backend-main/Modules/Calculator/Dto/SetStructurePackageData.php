<?php

namespace Modules\Calculator\Dto;


use Modules\Calculator\Models\Structure\Structure;
use Spatie\LaravelData\Data;

class SetStructurePackageData extends Data
{
    /**
     * Пакет - выбирается в выпадающем списке из существующих вариантов
     *
     * @param Structure $structure
     * @param int|null $top_node_id
     * @param int|null $package_id
     */
    public function __construct(
        public Structure $structure,
        public ?int      $top_node_id = null,
        public ?int      $package_id = null
    )
    {
    }


}
