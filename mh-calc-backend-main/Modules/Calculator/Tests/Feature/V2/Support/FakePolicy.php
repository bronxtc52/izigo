<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PolicyV2;

/** Фейковая версия политики T01 (id/config_hash — публичные свойства, как у домена T01). */
class FakePolicy extends PolicyV2
{
    public function __construct(public int $id = 42, public string $config_hash = 'cafebabe')
    {
    }
}
