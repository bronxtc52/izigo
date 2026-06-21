<?php

namespace Modules\Calculator\Facades;

use Illuminate\Support\Facades\Facade;
use Modules\Calculator\Models\CalculatorUserToken;

// @method static string|null email()
/**
 * @method static void setToken(CalculatorUserToken $token)
 * @method static CalculatorUserToken|null token()
 * @method static bool check()
 *
 * @see CalculatorAuthService
 */
class CalculatorAuth extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'calculator-auth';
    }
}
