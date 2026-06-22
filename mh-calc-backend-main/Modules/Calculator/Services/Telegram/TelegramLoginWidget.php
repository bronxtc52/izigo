<?php

namespace Modules\Calculator\Services\Telegram;

/**
 * Валидация данных Telegram Login Widget (вход в ВЕБ-админку, не Mini App) по
 * официальной схеме:
 *   secret_key = SHA256(bot_token)                       // ВНИМАНИЕ: raw-хэш, не HMAC
 *   hash       = HMAC_SHA256(key=secret_key, msg=data_check_string)
 * data_check_string — пары "key=value" по всем полям, КРОМЕ hash, отсортированные
 * по ключу, через "\n".
 *
 * Отличие от initData ({@see TelegramInitData}): там secret = HMAC("WebAppData", bot_token)
 * и поле user — JSON. Здесь поля плоские (id, first_name, username, auth_date, ...).
 * Токен бота сюда передаётся снаружи (из Key Vault через конфиг), не хранится в коде.
 */
final class TelegramLoginWidget
{
    /**
     * @param array<string,mixed> $data поля виджета (id, first_name, last_name,
     *                                   username, photo_url, auth_date, hash)
     * @return array<string,mixed>|null нормализованные поля либо null при неверной
     *                                  подписи / просроченном auth_date
     */
    public static function validate(array $data, string $botToken, int $maxAgeSeconds = 86400): ?array
    {
        if ($botToken === '' || empty($data['hash']) || !is_string($data['hash'])) {
            return null;
        }

        $providedHash = $data['hash'];
        unset($data['hash']);

        // data_check_string: "key=value" по всем полям кроме hash, сортировка по ключу, "\n".
        // Login Widget шлёт только плоские скаляры; не-скаляры отбрасываем намеренно
        // (fail-closed: если Telegram добавит вложенное поле, hash не сойдётся → честный отказ).
        $pairs = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $pairs[$key] = $key . '=' . (string) $value;
            }
        }
        ksort($pairs);
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash('sha256', $botToken, true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $providedHash)) {
            return null;
        }

        // Защита от replay: auth_date обязателен и не старше maxAgeSeconds.
        if ($maxAgeSeconds > 0) {
            $authDate = (int) ($data['auth_date'] ?? 0);
            if ($authDate <= 0 || (time() - $authDate) > $maxAgeSeconds) {
                return null;
            }
        }

        return $data;
    }
}
