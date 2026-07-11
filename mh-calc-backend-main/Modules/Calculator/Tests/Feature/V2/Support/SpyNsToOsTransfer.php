<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\NsToOsTransfer;

/** Шпион перевода НС→ОС: считает вызовы (идемпотентность окна — ровно один на месяц). */
class SpyNsToOsTransfer implements NsToOsTransfer
{
    /** @var array<int, array{0:string,1:int}> */
    public array $calls = [];

    public function executeForCalibratedMonth(string $month, int $factorBps): void
    {
        $this->calls[] = [$month, $factorBps];
    }
}
