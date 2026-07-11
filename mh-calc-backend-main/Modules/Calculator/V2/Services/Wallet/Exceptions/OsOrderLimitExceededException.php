<?php

namespace Modules\Calculator\V2\Services\Wallet\Exceptions;

/** mh-full-plan T02: оплата с ОС превышает лимит ≤70% стоимости заказа. */
class OsOrderLimitExceededException extends \DomainException
{
}
