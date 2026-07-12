<?php

namespace Modules\Calculator\V2\Services;

use RuntimeException;

/**
 * T01: на запрошенную дату нет действующей версии политики V2
 * (контракт PolicyVersionResolver::forDate — «бросает доменное исключение»).
 * Money-safe: расчёт без активной версии НЕ выполняется (fail-closed).
 */
class PolicyNotActiveException extends RuntimeException
{
    public static function forDate(\DateTimeInterface $at): self
    {
        return new self('Нет активной версии политики V2 на ' . $at->format('Y-m-d H:i:s') . ' UTC');
    }
}
