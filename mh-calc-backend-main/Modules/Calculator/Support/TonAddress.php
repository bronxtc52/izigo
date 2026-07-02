<?php

namespace Modules\Calculator\Support;

/**
 * Валидация user-friendly TON-адреса (B7, P1-hardening). Формат (официальная спецификация
 * TEP-2/адресов TON): base64 или base64url, 48 символов → 36 байт =
 * tag(1) + workchain(1) + account_hash(32) + CRC16(2, big-endian).
 *
 * - tag: 0x11 (bounceable) | 0x51 (non-bounceable); бит 0x80 = testnet-only → отклоняем
 *   (реквизиты вывода в проде обязаны быть mainnet);
 * - CRC16-CCITT/XModem (poly 0x1021, init 0x0000) по первым 34 байтам.
 *
 * Самопис осознанно (решение Гейта 2): в composer нет крипто/TON-зависимостей, зрелые
 * PHP-порты тянут GMP ради одной функции. Согласованность с фронтом (F3, @ton/core
 * Address.parseFriendly) закреплена общими тест-векторами Tests/Fixtures/ton-address-vectors.json,
 * сгенерированными самим @ton/core.
 */
final class TonAddress
{
    private const TAG_BOUNCEABLE = 0x11;
    private const TAG_NON_BOUNCEABLE = 0x51;
    private const TAG_TESTNET_FLAG = 0x80;

    /** Валидный mainnet user-friendly адрес (testnet-флаг → false). */
    public static function isValid(string $address): bool
    {
        $address = trim($address);
        if (strlen($address) !== 48) {
            return false;
        }

        // Принимаем оба алфавита (base64url — стандарт кошельков, base64 — легален по спеке).
        $bin = base64_decode(strtr($address, '-_', '+/'), true);
        if ($bin === false || strlen($bin) !== 36) {
            return false;
        }

        $tag = ord($bin[0]);
        if (($tag & self::TAG_TESTNET_FLAG) !== 0) {
            return false; // testnet-only адрес — не реквизит для прод-вывода
        }
        if (!in_array($tag, [self::TAG_BOUNCEABLE, self::TAG_NON_BOUNCEABLE], true)) {
            return false;
        }

        $expected = (ord($bin[34]) << 8) | ord($bin[35]); // CRC16 big-endian

        return self::crc16(substr($bin, 0, 34)) === $expected;
    }

    /** CRC16-CCITT (XModem): poly 0x1021, init 0x0000 — вариант из спецификации TON. */
    private static function crc16(string $data): int
    {
        $crc = 0x0000;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0
                    ? (($crc << 1) ^ 0x1021) & 0xFFFF
                    : ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
