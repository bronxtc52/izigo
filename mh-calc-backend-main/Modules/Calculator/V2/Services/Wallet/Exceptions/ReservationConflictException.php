<?php

namespace Modules\Calculator\V2\Services\Wallet\Exceptions;

/** mh-full-plan T02: конфликт резерва счетов (живой резерв/инвойс уже существует). */
class ReservationConflictException extends \DomainException
{
}
