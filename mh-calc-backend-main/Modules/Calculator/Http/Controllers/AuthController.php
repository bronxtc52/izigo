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
        // A-t1 (BFF): при включённом ADMIN_BFF_ENABLED токен выдаётся ТОЛЬКО прокси
        // (Next-сервер шлёт X-Admin-Proxy-Key server-side). Прямой вызов из браузера
        // (CORS '*') валидный payload виджета обменять на токен больше не может —
        // закрывает обход httpOnly-дизайна через XSS. Пустой настроенный ключ при
        // включённом флаге = fail-closed (мисконфиг не открывает дверь).
        if ((bool) config('calculator.admin_bff_enabled', false)) {
            $expected = (string) config('calculator.admin_proxy_key', '');
            $provided = (string) $request->header('X-Admin-Proxy-Key', '');
            if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
                return $this->error('Доступ запрещён', Response::HTTP_FORBIDDEN);
            }
        }

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

    /**
     * Выход из веб-админки: отзыв Sanctum-токена (G1). Утёкший bearer к денежной панели
     * должен отзываться — раньше роута отзыва не было (TTL 12ч закрывал только пассивно).
     * По умолчанию удаляем ТЕКУЩИЙ токен; ?all=1 — все токены участника (logout со всех
     * устройств). Гейтится web.admin, поэтому $request->user() уже резолвлен из bearer.
     */
    public function logout(Request $request): JsonResponse
    {
        $member = $request->user();
        if ($member === null) {
            return $this->error('Требуется вход', Response::HTTP_UNAUTHORIZED);
        }

        if ($request->boolean('all')) {
            $revoked = $member->tokens()->delete();
        } else {
            $current = $member->currentAccessToken();
            $revoked = $current !== null ? (int) $current->delete() : 0;
        }

        return response()->json(['status' => 'ok', 'revoked' => (int) $revoked]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], $status);
    }
}
