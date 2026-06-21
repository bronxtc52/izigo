<?php

namespace Modules\Calculator\Tests\Unit;

use Modules\Calculator\Services\Telegram\TelegramInitData;
use PHPUnit\Framework\TestCase;

/**
 * Валидатор Telegram initData (HMAC-SHA256). Чистый, без БД.
 */
class TelegramInitDataTest extends TestCase
{
    private const TOKEN = '123456:TEST_BOT_TOKEN';

    /** Подписать набор полей как это делает Telegram. */
    private function sign(array $params, string $token): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);

        return http_build_query($params);
    }

    public function testValidSignaturePasses(): void
    {
        $initData = $this->sign([
            'user' => json_encode(['id' => 777, 'first_name' => 'Tg', 'username' => 'tg777']),
            'auth_date' => time(),
            'query_id' => 'AAA',
        ], self::TOKEN);

        $result = TelegramInitData::validate($initData, self::TOKEN);

        $this->assertIsArray($result);
        $this->assertSame(777, $result['user']['id']);
    }

    public function testForgedHashFails(): void
    {
        $initData = $this->sign([
            'user' => json_encode(['id' => 1]),
            'auth_date' => time(),
        ], self::TOKEN);

        // Чужой/неверный токен → подпись не сойдётся.
        $this->assertNull(TelegramInitData::validate($initData, 'WRONG_TOKEN'));
    }

    public function testTamperedDataFails(): void
    {
        $initData = $this->sign([
            'user' => json_encode(['id' => 1]),
            'auth_date' => time(),
        ], self::TOKEN);

        $tampered = $initData . '&injected=1';
        $this->assertNull(TelegramInitData::validate($tampered, self::TOKEN));
    }

    public function testExpiredAuthDateFails(): void
    {
        $initData = $this->sign([
            'user' => json_encode(['id' => 1]),
            'auth_date' => time() - 100000,
        ], self::TOKEN);

        $this->assertNull(TelegramInitData::validate($initData, self::TOKEN, 86400));
    }

    public function testMissingAuthDateFails(): void
    {
        // Строгая replay-защита: без auth_date — отказ (даже при валидной подписи).
        $initData = $this->sign(['user' => json_encode(['id' => 1])], self::TOKEN);
        $this->assertNull(TelegramInitData::validate($initData, self::TOKEN, 86400));

        // Но при отключённой проверке возраста (maxAge=0) — проходит.
        $this->assertIsArray(TelegramInitData::validate($initData, self::TOKEN, 0));
    }

    public function testMissingHashFails(): void
    {
        $this->assertNull(TelegramInitData::validate('user=%7B%7D&auth_date=1', self::TOKEN));
    }

    public function testEmptyInputFails(): void
    {
        $this->assertNull(TelegramInitData::validate('', self::TOKEN));
        $this->assertNull(TelegramInitData::validate('x=1&hash=abc', ''));
    }
}
