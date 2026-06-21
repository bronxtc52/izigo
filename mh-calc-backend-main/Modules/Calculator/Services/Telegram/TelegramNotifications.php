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
}
