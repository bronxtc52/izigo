<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Facades\CalculatorAuth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserTokenMiddleware
{
    /**
     * Проверит есть ли активный токен пользователя калькулятора
     *
     * @param Request $request
     * @param Closure $next
     * @return \Illuminate\Http\JsonResponse|mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (CalculatorAuth::check()) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid or expired token',
            'need_login' => true
        ], Response::HTTP_FORBIDDEN);

    }
}
