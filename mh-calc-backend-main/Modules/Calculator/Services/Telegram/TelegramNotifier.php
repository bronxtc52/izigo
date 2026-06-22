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

    /**
     * Best-effort отправка (биллинг/активации): ошибки глотаются, статус не возвращается.
     * Для outbox-диспетчера, которому нужен учёт успеха/ошибки — см. deliver().
     */
    public function notify(?int $chatId, string $html): void
    {
        $this->deliver($chatId, $html);
    }

    /**
     * Доставка с учётом результата (для C1 outbox-диспетчера):
     *   - 'sent'    — Telegram принял (2xx);
     *   - 'skipped' — отправка невозможна без ретрая (выключено/нет токена/нет chat_id);
     *   - 'retry'   — временная ошибка (сеть/429/5xx) — есть смысл повторить;
     *   - 'failed'  — терминальная ошибка (4xx: chat not found, bot blocked, bad html).
     * НИКОГДА не возвращает текст ответа/URL (в URL — токен бота).
     */
    public function deliver(?int $chatId, string $html): string
    {
        if (!$this->isEnabled() || !$chatId || $chatId <= 0) {
            return 'skipped';
        }

        $token = (string) config('calculator.telegram_bot_token', '');

        try {
            $response = Http::timeout(4)->asForm()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            // Транспортная ошибка (таймаут/сеть) — временная, имеет смысл повторить.
            // НЕ логируем message/URL (токен в пути).
            return 'retry';
        }

        if ($response->successful()) {
            return 'sent';
        }

        $status = $response->status();
        // 429 (rate limit) и 5xx — временные; 4xx (кроме 429) — терминальные.
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }

        return 'failed';
    }
}
