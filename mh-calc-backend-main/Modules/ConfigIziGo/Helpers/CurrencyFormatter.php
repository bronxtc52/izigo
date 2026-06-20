<?php

namespace Modules\ConfigIziGo\Helpers;


class CurrencyFormatter
{
    const DECIMALS = 0;

    public static function pv(float $amount): string
    {
        $amount = number_format($amount, self::DECIMALS, '.', ' ');
        return "{$amount} PV";
    }

    public static function bv(float $amount): string
    {
        $amount = number_format($amount, self::DECIMALS, '.', ' ');
        return "{$amount} BV";
    }

    public static function fiat(float $amount, string $currency): string
    {
        $amount = number_format($amount, self::DECIMALS, '.', ' ');
        return "{$amount} {$currency}";
    }
}
