<?php

namespace Modules\Calculator\Tests\Feature\Concerns;

use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Role;
use Modules\Calculator\Services\MemberService;

/**
 * Хелперы для тестов авторизации платформы через Telegram initData (единственный
 * способ входа). Подписывает initData валидным bot-токеном и собирает заголовки.
 */
trait SignsTelegramInitData
{
    protected string $botToken = '123456:TEST_BOT_TOKEN';

    /** Настроить тестовый bot-токен (вызывать в setUp после parent::setUp()). */
    protected function bootTelegram(): void
    {
        config(['calculator.telegram_bot_token' => $this->botToken]);
    }

    /** Подписать произвольный набор параметров по схеме Telegram WebApp. */
    protected function signInitData(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $secret = hash_hmac('sha256', $this->botToken, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);

        return http_build_query($params);
    }

    /** Валидный initData для участника tgId (name = first_name для опознавания в тестах). */
    protected function initData(int $tgId, ?string $startParam = null, ?string $name = null): string
    {
        $params = [
            'user' => json_encode([
                'id' => $tgId,
                'first_name' => $name ?? "U{$tgId}",
                'username' => "u{$tgId}",
            ]),
            'auth_date' => time(),
            'query_id' => 'AAA',
        ];
        if ($startParam !== null) {
            $params['start_param'] = $startParam;
        }

        return $this->signInitData($params);
    }

    protected function tgHeaders(string $initData): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest', 'X-Telegram-Init-Data' => $initData];
    }

    /**
     * Заголовки ВЕБ-админки: участник, опознанный по initData, получает Sanctum-токен
     * (как после входа через Telegram Login Widget). Админ-эндпоинты теперь под web.admin,
     * не initData. Участник должен уже существовать (предварительный registerTg/cabinet me).
     */
    protected function adminHeaders(string $initData): array
    {
        parse_str($initData, $params);
        $user = json_decode((string) ($params['user'] ?? '{}'), true);
        $member = $this->memberByTg((int) ($user['id'] ?? 0));
        $token = $member->createToken('test-web-admin')->plainTextToken;

        return ['X-Requested-With' => 'XMLHttpRequest', 'Authorization' => 'Bearer ' . $token];
    }

    /**
     * Создать УЧАСТНИКА (member) в дереве — фикстура для тестов, которым нужен готовый
     * партнёр. В боевой воронке участник появляется только при первой покупке (промоушн
     * лида); тут создаём напрямую через MemberService (как при оплате/сидировании), чтобы
     * не гонять весь чекаут. Возвращает [initData, ref_code].
     */
    protected function registerTg(int $tgId, ?string $sponsorRef = null, ?string $name = null): array
    {
        $member = app(MemberService::class)->registerTelegram(
            $tgId,
            $name ?? "U{$tgId}",
            "u{$tgId}",
            $sponsorRef,
            null,
        );
        $initData = $this->initData($tgId, $sponsorRef, $name);

        return [$initData, $member->ref_code];
    }

    /**
     * Создать ЛИДА через боевой путь (первый заход в Mini App по реф-ссылке спонсора).
     * Возвращает [initData, data] (data — leadState из /cabinet/me).
     */
    protected function makeLead(int $tgId, string $sponsorRef, ?string $name = null): array
    {
        $initData = $this->initData($tgId, $sponsorRef, $name);
        $data = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($initData))
            ->assertOk()->json('data');

        return [$initData, $data];
    }

    protected function memberByTg(int $tgId): Member
    {
        return Member::where('telegram_id', $tgId)->firstOrFail();
    }

    /** Выдать роль участнику напрямую (минуя owner-бутстрап). */
    protected function grantRole(int $tgId, string $role, ?int $scopeMemberId = null): void
    {
        $member = $this->memberByTg($tgId);
        $roleId = Role::where('name', $role)->value('id');
        $member->roles()->syncWithoutDetaching([$roleId => ['leader_scope_member_id' => $scopeMemberId]]);
    }
}
