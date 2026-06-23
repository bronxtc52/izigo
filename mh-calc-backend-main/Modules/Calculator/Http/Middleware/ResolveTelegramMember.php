<?php

namespace Modules\Calculator\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Modules\Calculator\Services\OwnerBootstrap;
use Modules\Calculator\Services\Telegram\MiniAppAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Единственная авторизация платформы: Telegram initData (заголовок X-Telegram-Init-Data).
 * Валидирует подпись и резолвит идентичность: участник (member) ИЛИ лид (ещё не купил).
 * Кладёт обоих в request->attributes (member|lead, любой может быть null). Бутстрап роли
 * owner — только для участника. Невалидный initData → 401. Валидный юзер без member/lead
 * (нет спонсора) проходит без идентичности — контроллер /me вернёт need_referral.
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
            $identity = $this->auth->resolveIdentity((string) $request->header('X-Telegram-Init-Data', ''));
        } catch (RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'need_login' => true,
            ], Response::HTTP_UNAUTHORIZED);
        }

        $member = $identity['member'] ?? null;
        $lead = $identity['lead'] ?? null;

        if ($member !== null) {
            $this->ownerBootstrap->ensure($member);
        }
        $request->attributes->set('member', $member);
        $request->attributes->set('lead', $lead);

        return $next($request);
    }
}
