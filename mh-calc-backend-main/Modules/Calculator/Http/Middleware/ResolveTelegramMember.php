<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Modules\Calculator\Services\OwnerBootstrap;
use Modules\Calculator\Services\Telegram\MiniAppAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Единственная авторизация платформы: Telegram initData (заголовок
 * X-Telegram-Init-Data). Валидирует подпись, резолвит/создаёт участника по
 * telegram_id и кладёт его в request->attributes('member'). Бутстрапит роль owner
 * для telegram_id из конфига (OWNER_TELEGRAM_IDS, источник — Key Vault).
 * Никакого email/пароля/токена — only Telegram.
 */
class ResolveTelegramMember
{
    public function __construct(
        private readonly MiniAppAuth $auth,
        private readonly OwnerBootstrap $ownerBootstrap,
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $member = $this->auth->resolveMember((string) $request->header('X-Telegram-Init-Data', ''));
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'need_login' => true,
            ], Response::HTTP_UNAUTHORIZED);
        }

        $this->ownerBootstrap->ensure($member);
        $request->attributes->set('member', $member);

        return $next($request);
    }
}
