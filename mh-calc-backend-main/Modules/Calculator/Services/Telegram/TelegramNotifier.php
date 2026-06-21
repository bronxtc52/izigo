<?php

namespace Modules\Calculator\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Исходящие уведомления в Telegram (Bot API sendMessage, parse_mode=HTML).
 * Best-effort: ошибки доставки НЕ должны ломать основной поток (активацию/расчёт).
 * Opt-in: работает только при включённом флаге и наличии токена (из KV).
 */
class TelegramNotifier
{
    public function isEnabled(): bool
    {
        return (bool) config('calculator.telegram_notify_enabled', false)
            && (string) config('calculator.telegram_bot_token', '') !== '';
    }

    public function notify(?int $chatId, string $html): void
    {
        if (!$this->isEnabled() || !$chatId || $chatId <= 0) {
            return;
        }

        $token = (string) config('calculator.telegram_bot_token', '');

        try {
            Http::timeout(4)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            // Молча: доставка уведомления не критична для биллинга.
            // ВНИМАНИЕ: НЕ логировать $e->getMessage()/URL — содержит токен бота в пути.
        }
    }
}
