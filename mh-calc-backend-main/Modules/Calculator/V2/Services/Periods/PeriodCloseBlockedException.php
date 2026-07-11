<?php

namespace Modules\Calculator\V2\Services\Periods;

/**
 * V2 T04: предикат зависимостей закрытия не выполнен (month close при незакрытом
 * half-month; quarter payout при <3 закрытых месяцах; период ещё не истёк).
 * Job помечается FAILED без постингов, период остаётся open — ретрай следующим тиком.
 */
class PeriodCloseBlockedException extends \DomainException
{
}
