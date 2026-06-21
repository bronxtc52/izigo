<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Tests\TestCase;

/**
 * Telegram Mini App: авторизация по initData, создание участника по telegram_id,
 * доступ к данным кабинета и активация; отказ при неверной подписи.
 */
class MiniAppTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:TEST_BOT_TOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        config(['calculator.telegram_bot_token' => self::TOKEN]);
    }

    private function sign(array $params): string
    {
        ksort($params);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $secret = hash_hmac('sha256', self::TOKEN, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);

        return http_build_query($params);
    }

    private function initData(int $tgId, ?string $startParam = null): string
    {
        $params = [
            'user' => json_encode(['id' => $tgId, 'first_name' => 'Tg', 'username' => "u{$tgId}"]),
            'auth_date' => time(),
            'query_id' => 'AAA',
        ];
        if ($startParam !== null) {
            $params['start_param'] = $startParam;
        }

        return $this->sign($params);
    }

    private function headers(string $initData): array
    {
        return ['X-Requested-With' => 'XMLHttpRequest', 'X-Telegram-Init-Data' => $initData];
    }

    public function testMeCreatesMemberFromInitData(): void
    {
        $res = $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(555)))->assertOk();

        $this->assertSame('success', $res->json('status'));
        $this->assertNotEmpty($res->json('data.member.ref_code'));
        $this->assertDatabaseHas('members', ['telegram_id' => 555]);
    }

    public function testSecondCallReusesSameMember(): void
    {
        $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(556)))->assertOk();
        $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(556)))->assertOk();

        $this->assertSame(1, Member::where('telegram_id', 556)->count());
    }

    public function testForgedInitDataRejected(): void
    {
        // Подпись неверным токеном.
        $secret = hash_hmac('sha256', 'WRONG', 'WebAppData', true);
        $bad = http_build_query([
            'user' => json_encode(['id' => 1]),
            'auth_date' => time(),
            'hash' => hash_hmac('sha256', 'x', $secret),
        ]);

        $this->getJson('/api/v1/miniapp/me', $this->headers($bad))->assertStatus(401);
    }

    public function testMissingInitDataRejected(): void
    {
        $this->getJson('/api/v1/miniapp/me', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }

    public function testEmptyBotTokenRejects(): void
    {
        // Мисконфиг: токен не подтянулся из KV → безопасный фейл 401 (а не «все проходят»).
        config(['calculator.telegram_bot_token' => '']);
        $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(999)))->assertStatus(401);
    }

    public function testConcurrentResolveReusesMember(): void
    {
        // Симуляция гонки: участник уже создан другим параллельным запросом с тем же
        // telegram_id — повторный резолв возвращает его, без дубля и без 500.
        Member::create([
            'telegram_id' => 888,
            'name' => 'tg:888',
            'ref_code' => 'TGRACE01',
            'status' => 'registered',
            'path' => '1',
        ]);

        $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(888)))->assertOk();
        $this->assertSame(1, Member::where('telegram_id', 888)->count());
    }

    public function testActivateViaMiniApp(): void
    {
        $initData = $this->initData(777);
        $this->postJson('/api/v1/miniapp/activate-package', ['package_id' => 1], $this->headers($initData))
            ->assertOk();

        $this->assertDatabaseHas('members', ['telegram_id' => 777, 'status' => 'active', 'package_id' => 1]);
    }

    public function testStartParamLinksSponsor(): void
    {
        // Спонсор-участник (корень) с реф-кодом.
        $sponsor = $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(100)))->json('data.member');
        $ref = $sponsor['ref_code'];

        $this->getJson('/api/v1/miniapp/me', $this->headers($this->initData(101, $ref)))->assertOk();

        $child = Member::where('telegram_id', 101)->first();
        $sponsorMember = Member::where('telegram_id', 100)->first();
        $this->assertSame($sponsorMember->id, $child->sponsor_id);
    }
}
