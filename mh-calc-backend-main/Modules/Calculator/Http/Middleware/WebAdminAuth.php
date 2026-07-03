<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Calculator\Models\Member;
use Symfony\Component\HttpFoundation\Response;

/**
 * Авторизация ВЕБ-админки (admin.izigo.adarasoft.com): Bearer Sanctum-токен,
 * выданный после входа через Telegram Login Widget ({@see \Modules\Calculator\Http\Controllers\AuthController}).
 * Резолвит участника из токена (tokenable = Member) и кладёт его в тот же
 * request->attributes('member'), что и telegram.auth — чтобы RoleMiddleware
 * (calculator.role:*) работал БЕЗ изменений. Применять ПЕРЕД calculator.role.
 */
class WebAdminAuth
{
    public function handle(Request $request, Closure $next): mixed
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return $this->unauthorized();
        }

        $token = PersonalAccessToken::findToken($bearer);
        if ($token === null) {
            return $this->unauthorized();
        }

        // Истечение токена (если sanctum.expiration задан — findToken его не проверяет).
        if ($token->expires_at !== null && Carbon::parse($token->expires_at)->isPast()) {
            return $this->unauthorized();
        }

        $member = $token->tokenable;
        if (!$member instanceof Member) {
            return $this->unauthorized();
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('member', $member);

        // Привязываем токен к участнику и делаем его текущим user'ом запроса, чтобы работал
        // канонический Sanctum-API ($request->user()->currentAccessToken()) — нужно для logout (G1).
        $member->withAccessToken($token);
        $request->setUserResolver(fn () => $member);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Требуется вход',
            'need_login' => true,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
