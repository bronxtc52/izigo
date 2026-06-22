<?php

namespace Modules\Calculator\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\OwnerBootstrap;
use Modules\Calculator\Services\Telegram\TelegramLoginWidget;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход в ВЕБ-админку (admin.izigo.adarasoft.com) через Telegram Login Widget.
 * В отличие от Mini App (initData), здесь поток браузерный: виджет отдаёт подписанные
 * поля, мы валидируем подпись bot_token'ом, резолвим участника по telegram_id и —
 * если у него есть админ-роли — выдаём Sanctum-токен. Веб-логин НЕ регистрирует новых
 * участников (кроме бутстрапа владельца из OWNER_TELEGRAM_IDS): админ должен уже существовать.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly MemberService $members,
        private readonly OwnerBootstrap $ownerBootstrap,
    ) {
    }

    public function telegramLogin(Request $request): JsonResponse
    {
        $botToken = (string) config('calculator.telegram_bot_token', '');
        $maxAge = (int) config('calculator.telegram_login_max_age', 86400);

        $data = TelegramLoginWidget::validate($request->all(), $botToken, $maxAge);
        if ($data === null || empty($data['id'])) {
            return $this->error('Невалидные данные входа Telegram', Response::HTTP_UNAUTHORIZED);
        }

        $tgId = (int) $data['id'];
        $member = Member::query()->where('telegram_id', $tgId)->first();

        // Бутстрап владельца: если участника ещё нет, но telegram_id в OWNER_TELEGRAM_IDS —
        // регистрируем его (без спонсора), чтобы первый владелец мог войти в веб без Mini App.
        if ($member === null && $this->ownerBootstrap->isOwner($tgId)) {
            $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: ($data['username'] ?? null);
            $member = $this->members->registerTelegram($tgId, $name, $data['username'] ?? null);
        }

        if ($member === null) {
            return $this->error('Доступ запрещён', Response::HTTP_FORBIDDEN);
        }

        $this->ownerBootstrap->ensure($member);

        // В админку пускаем только участников с ролями (как isAdmin в Mini App).
        if (!$member->roles()->exists()) {
            return $this->error('Доступ запрещён', Response::HTTP_FORBIDDEN);
        }

        // Источник прав — RBAC (RoleMiddleware по ролям member), не Sanctum-abilities, поэтому
        // abilities оставляем дефолтными ['*']. TTL ограничиваем: вечный bearer к денежной
        // панели — лишний риск; logout/отзыв — в следующих блоках.
        $ttlMinutes = (int) config('calculator.web_admin_token_ttl_minutes', 720);
        $expiresAt = $ttlMinutes > 0 ? now()->addMinutes($ttlMinutes) : null;
        $plainToken = $member->createToken('web-admin', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'token' => $plainToken,
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'telegram_username' => $member->telegram_username,
                'roles' => $member->roles()->pluck('name')->all(),
            ],
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
