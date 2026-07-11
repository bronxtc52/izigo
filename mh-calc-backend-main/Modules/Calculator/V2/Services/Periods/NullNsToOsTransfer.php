<?php

namespace Modules\Calculator\V2\Services\Periods;

use Modules\Calculator\V2\Contracts\NsToOsTransfer;

/**
 * V2 T04: Null-дефолт перевода НС→ОС — делает T04 безопасно мерджимым до T02.
 * Реальную ledger-проводку даёт T02 (executeForCalibratedMonth, MF-4/MF-6);
 * биндинг перебивается в маркер-блоке T02 CalculatorV2ServiceProvider (bindIf у T04).
 * До T02 перевод — no-op: денег не двигает, идемпотентность окна журналируется джобом.
 */
class NullNsToOsTransfer implements NsToOsTransfer
{
    public function executeForCalibratedMonth(string $month, int $factorBps): void
    {
        // no-op до merge T02
    }
}
