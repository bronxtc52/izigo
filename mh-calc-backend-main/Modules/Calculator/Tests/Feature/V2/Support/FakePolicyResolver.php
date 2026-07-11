<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;

/** Фейковый резолвер политики: всегда одна версия (контракт MF-5). */
class FakePolicyResolver implements PolicyVersionResolver
{
    private readonly FakePolicy $policy;

    public function __construct(?FakePolicy $policy = null)
    {
        $this->policy = $policy ?? new FakePolicy();
    }

    public function forDate(\DateTimeInterface $at): PolicyV2
    {
        return $this->policy;
    }

    public function current(): PolicyV2
    {
        return $this->policy;
    }
}
