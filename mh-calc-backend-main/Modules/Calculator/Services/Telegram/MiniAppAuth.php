<?php

namespace Modules\Calculator\Services\Telegram;

use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\MemberService;

/**
 * Авторизация Mini App по initData: валидирует подпись, резолвит участника по
 * telegram_id; при первом входе — Telegram-нативная регистрация (без email),
 * спонсор из start_param реф-ссылки.
 */
class MiniAppAuth
{
    public function __construct(private readonly MemberService $members)
    {
    }

    public function resolveMember(string $initData): Member
    {
        $token = (string) config('calculator.telegram_bot_token', '');
        $maxAge = (int) config('calculator.telegram_initdata_max_age', 86400);

        $data = TelegramInitData::validate($initData, $token, $maxAge);
        if ($data === null || empty($data['user']['id'])) {
            throw new RuntimeException('Невалидный initData');
        }

        $tgId = (int) $data['user']['id'];
        $existing = Member::query()->where('telegram_id', $tgId)->first();
        if ($existing !== null) {
            return $existing;
        }

        $user = $data['user'];
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
            ?: ($user['username'] ?? null);

        try {
            return $this->members->registerTelegram(
                $tgId,
                $name,
                $user['username'] ?? null,
                $data['start_param'] ?? null,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Гонка: параллельный запрос с тем же initData уже создал участника
            // (Mini App шлёт несколько запросов разом на первом входе).
            $member = Member::query()->where('telegram_id', $tgId)->first();
            if ($member !== null) {
                return $member;
            }
            throw new RuntimeException('Не удалось создать участника');
        }
    }
}
