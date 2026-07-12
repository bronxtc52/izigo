<?php

namespace Modules\Calculator\V2\Services\Refunds\Exceptions;

/**
 * T12: нарушение бизнес-правил возврата (HTTP 422): заказ не оплачен, qty>ordered,
 * повторный полный возврат, пустой набор строк и т.п.
 */
class RefundValidationException extends \RuntimeException
{
}
