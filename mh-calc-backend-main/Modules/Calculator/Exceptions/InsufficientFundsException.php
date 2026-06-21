<?php

namespace Modules\Calculator\Exceptions;

use RuntimeException;

/**
 * Недостаточно доступного баланса для списания (покупка/autoship). Отдельный тип,
 * чтобы autoship (S6) мог отличить нехватку средств (→ retry) от прочих ошибок.
 */
class InsufficientFundsException extends RuntimeException
{
}
