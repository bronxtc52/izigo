<?php

namespace Modules\Calculator\V2\Services\Read;

/**
 * mh-full-plan T14: единый формат денег для read-слоя Mini App. Деньги — integer
 * USD-центы; строковое decimal-представление считается БЕЗ float (образец
 * AccountsV2Controller::centsToDecimal / WalletService::centsToDecimal). Ни один
 * read-сервис T14 не отдаёт float в JSON (тест-инвариант плана).
 */
trait CentsFormat
{
    /** Центы → строка decimal "D.CC" без float (знак сохраняется). */
    protected function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
