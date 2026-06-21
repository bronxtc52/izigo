<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Facades\CalculatorAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC-гейт: пропускает, если у текущего пользователя есть любая из ролей.
 * owner проходит всегда. Применять ПОСЛЕ calculator.validate.token.
 * Использование: ->middleware('calculator.role:owner,support')
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = CalculatorAuth::token()?->user;

        if ($user && ($user->isOwner() || ($roles !== [] && $user->hasAnyRole($roles)))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Недостаточно прав',
        ], Response::HTTP_FORBIDDEN);
    }
}
