<?php

namespace Modules\Calculator\Services\Telegram;

/**
 * Валидация Telegram Mini App initData (HMAC-SHA256) по официальной схеме:
 *   secret_key = HMAC_SHA256(key="WebAppData", msg=bot_token)
 *   hash       = HMAC_SHA256(key=secret_key, msg=data_check_string)
 * data_check_string — пары "key=value" (кроме hash), отсортированные по ключу, через "\n".
 * Токен бота НЕ хранится здесь — передаётся снаружи (из Key Vault через конфиг).
 */
final class TelegramInitData
{
    /**
     * @return array|null Разобранные поля initData (с декодированным user) либо null,
     *                    если подпись неверна или данные просрочены.
     */
    public static function validate(string $initData, string $botToken, int $maxAgeSeconds = 86400): ?array
    {
        if ($initData === '' || $botToken === '') {
            return null;
        }

        parse_str($initData, $params);
        if (!isset($params['hash']) || !is_string($params['hash'])) {
            return null;
        }
        $providedHash = $params['hash'];
        unset($params['hash']);

        // data_check_string: сортировка по ключу, "key=value" через перевод строки.
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $providedHash)) {
            return null;
        }

        // Защита от replay: auth_date обязателен и не старше maxAgeSeconds.
        if ($maxAgeSeconds > 0) {
            $authDate = (int) ($params['auth_date'] ?? 0);
            if ($authDate <= 0 || (time() - $authDate) > $maxAgeSeconds) {
                return null;
            }
        }

        if (isset($params['user']) && is_string($params['user'])) {
            $decoded = json_decode($params['user'], true);
            if (is_array($decoded)) {
                $params['user'] = $decoded;
            }
        }

        return $params;
    }
}
