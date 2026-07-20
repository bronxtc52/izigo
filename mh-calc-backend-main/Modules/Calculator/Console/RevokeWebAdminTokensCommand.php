<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Одноразовая команда выката t1-admin-cookie-auth (решение Гейта A): принудительный отзыв
 * ВСЕХ существующих Sanctum-токенов веб-админки (name='web-admin'). До перехода на
 * httpOnly-cookie токены жили в localStorage браузеров админов — revoke мгновенно
 * закрывает это XSS-окно (цена — один re-login через Telegram Login Widget).
 * Токены других имён (например, тестовые/Mini App-потоки) НЕ трогаются.
 * Запуск — на выкате из контейнера: php artisan calculator:revoke-web-admin-tokens
 */
class RevokeWebAdminTokensCommand extends Command
{
    protected $signature = 'calculator:revoke-web-admin-tokens';

    protected $description = 'Отозвать ВСЕ Sanctum-токены веб-админки (name=web-admin) — одноразовый шаг выката BFF-cookie';

    public function handle(): void
    {
        $revoked = PersonalAccessToken::query()->where('name', 'web-admin')->delete();
        $this->info("Отозвано web-admin токенов: {$revoked}. Админы перелогинятся через Login Widget.");
    }
}
