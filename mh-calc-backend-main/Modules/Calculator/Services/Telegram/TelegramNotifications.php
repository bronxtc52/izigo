<?php

namespace Modules\Calculator\Services\Telegram;

/**
 * Шаблоны уведомлений (ru, Telegram-HTML). Динамика экранируется (htmlspecialchars):
 * имена/значения наружу в Telegram-HTML без экранирования роняют парсер и опасны.
 */
final class TelegramNotifications
{
    private static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function packageActivated(string $packageName, string $totalDollars): string
    {
        return "✅ Пакет <b>" . self::e($packageName) . "</b> активирован.\n"
            . "Ваш доход: <b>$" . self::e($totalDollars) . "</b>.";
    }

    public static function rankAchieved(string $rankAlias): string
    {
        return "🏆 Поздравляем! Достигнут ранг <b>" . self::e($rankAlias) . "</b>.";
    }

    public static function newReferralActivated(string $partnerName): string
    {
        return "🎉 Ваш партнёр <b>" . self::e($partnerName) . "</b> активировал пакет — "
            . "проверьте доход и команду в приложении.";
    }

    /**
     * C1: статус заявки на вывод. amountDollars — строка "D.CC" (без знака валюты).
     * status ∈ approved|paid|rejected|cancelled. Динамика экранируется.
     */
    public static function payoutStatus(string $status, string $amountDollars, ?string $reason = null): string
    {
        $amount = '<b>$' . self::e($amountDollars) . '</b>';
        $line = match ($status) {
            'approved' => "✅ Заявка на вывод {$amount} одобрена.",
            'paid' => "💸 Вывод {$amount} выплачен.",
            'rejected' => "⛔️ Заявка на вывод {$amount} отклонена.",
            'cancelled' => "↩️ Заявка на вывод {$amount} отменена, средства возвращены на баланс.",
            default => "ℹ️ Статус заявки на вывод {$amount} изменён: <b>" . self::e($status) . "</b>.",
        };
        if ($reason !== null && trim($reason) !== '') {
            $line .= "\nПричина: " . self::e($reason);
        }

        return $line;
    }

    /**
     * Нормализация markdown-текста админа в безопасный Telegram-HTML (для рассылок).
     * Правило llm-output-formatting: сначала экранируем спецсимволы (<,>,&), затем
     * markdown → Telegram-HTML. В БД хранится СЫРЬЁ; нормализация — только на выходе.
     */
    public static function mdToTelegramHtml(string $raw): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];

        foreach ($lines as $line) {
            // 1) Экранируем спецсимволы HTML до любых подстановок тегов.
            $s = self::e($line);

            // 2) Заголовки markdown (## ...) → жирная строка.
            if (preg_match('/^\s{0,3}#{1,6}\s+(.*)$/u', $line, $m)) {
                $out[] = '<b>' . self::e($m[1]) . '</b>';
                continue;
            }

            // 3) Маркеры списка (-, *) в начале строки → bullet.
            $s = preg_replace('/^(\s*)[-*]\s+/u', '$1• ', $s);

            // 4) Inline: **bold** / __bold__ → <b>, *italic* / _italic_ → <i>, `code` → <code>.
            $s = preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $s);
            $s = preg_replace('/__(.+?)__/u', '<b>$1</b>', $s);
            $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/u', '<i>$1</i>', $s);
            $s = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/u', '<i>$1</i>', $s);
            $s = preg_replace('/`(.+?)`/u', '<code>$1</code>', $s);

            $out[] = $s;
        }

        return trim(implode("\n", $out));
    }
}
