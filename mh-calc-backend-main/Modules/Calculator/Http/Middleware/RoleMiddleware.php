<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC-гейт: пропускает, если у текущего участника есть любая из ролей.
 * owner проходит всегда. Применять ПОСЛЕ telegram.auth (участник в request('member')).
 * Использование: ->middleware('calculator.role:owner,support')
 */
class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');

        if ($member && ($member->isOwner() || ($roles !== [] && $member->hasAnyRole($roles)))) {
            return $next($request);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Недостаточно прав',
        ], Response::HTTP_FORBIDDEN);
    }
}
