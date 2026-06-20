<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\CalculatorUserToken;

class SetCalculatorUserMiddleware
{
    /**
     * Поищет активный токен пользователя калькулятора,
     * совпадающий с header('CalculatorAuthToken')
     * Если найдет, то запишет его в CalculatorAuth (статика).
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->header('CalculatorAuthToken');

        /** @var CalculatorUserToken $userToken */
        $userToken = $token ? CalculatorUserToken::where('token', $token)->first() : null;
        if ($userToken && $userToken->isValid()) {
            // Устанавливаем текущий токен в сервис
            CalculatorAuth::setToken($userToken);
        }

        return $next($request);
    }
}
