<?php

namespace Modules\Calculator\Tests\Unit;

use Modules\Calculator\Support\TonAddress;
use PHPUnit\Framework\TestCase;

/**
 * B7 (P1-hardening): валидация user-friendly TON-адреса (CRC16-CCITT + testnet reject).
 * Векторы — Tests/Fixtures/ton-address-vectors.json, сгенерированы @ton/core (та же
 * библиотека валидирует адрес на фронте, F3) — расхождение бэка и фронта ловится здесь.
 */
class TonAddressTest extends TestCase
{
    private static function vectors(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/ton-address-vectors.json'),
            true,
        );
    }

    public function testValidMainnetAddressesAccepted(): void
    {
        foreach (self::vectors()['valid'] as $v) {
            $this->assertTrue(TonAddress::isValid($v['address']), "должен приниматься: {$v['note']}");
        }
    }

    public function testTestnetAddressesRejected(): void
    {
        foreach (self::vectors()['testnet_rejected'] as $v) {
            $this->assertFalse(TonAddress::isValid($v['address']), "testnet должен отклоняться: {$v['note']}");
        }
    }

    public function testInvalidAddressesRejected(): void
    {
        foreach (self::vectors()['invalid'] as $v) {
            $this->assertFalse(TonAddress::isValid($v['address']), "должен отклоняться: {$v['note']}");
        }
    }

    public function testWhitespaceTrimmed(): void
    {
        $valid = self::vectors()['valid'][0]['address'];
        $this->assertTrue(TonAddress::isValid("  {$valid}\n"));
    }
}
