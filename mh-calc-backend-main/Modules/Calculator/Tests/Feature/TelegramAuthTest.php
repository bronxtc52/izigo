<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Авторизация платформы — ТОЛЬКО Telegram initData: создание участника по
 * telegram_id при первом входе, повторное использование, отказ при неверной/пустой
 * подписи, привязка спонсора по start_param и бутстрап владельца из конфига.
 */
class TelegramAuthTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testMeCreatesMemberFromInitData(): void
    {
        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(555)))->assertOk();

        $this->assertSame('success', $res->json('status'));
        $this->assertNotEmpty($res->json('data.member.ref_code'));
        $this->assertDatabaseHas('members', ['telegram_id' => 555]);
    }

    public function testSecondCallReusesSameMember(): void
    {
        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(556)))->assertOk();
        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(556)))->assertOk();

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

        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($bad))->assertStatus(401);
    }

    public function testMissingInitDataRejected(): void
    {
        $this->getJson('/api/v1/cabinet/me', ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(401);
    }

    public function testEmptyBotTokenRejects(): void
    {
        // Мисконфиг: токен не подтянулся из KV → безопасный фейл 401 (а не «все проходят»).
        config(['calculator.telegram_bot_token' => '']);
        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(999)))->assertStatus(401);
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

        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(888)))->assertOk();
        $this->assertSame(1, Member::where('telegram_id', 888)->count());
    }

    public function testActivateViaCabinet(): void
    {
        $initData = $this->initData(777);
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($initData))
            ->assertOk();

        $this->assertDatabaseHas('members', ['telegram_id' => 777, 'status' => 'active', 'package_id' => 1]);
    }

    public function testStartParamLinksSponsor(): void
    {
        // Спонсор-участник (корень) с реф-кодом.
        $sponsor = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(100)))->json('data.member');
        $ref = $sponsor['ref_code'];

        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(101, $ref)))->assertOk();

        $child = Member::where('telegram_id', 101)->first();
        $sponsorMember = Member::where('telegram_id', 100)->first();
        $this->assertSame($sponsorMember->id, $child->sponsor_id);
    }

    public function testOwnerBootstrapFromConfig(): void
    {
        // telegram_id из OWNER_TELEGRAM_IDS получает роль owner при первом входе
        // и сразу имеет доступ к админке.
        config(['calculator.owner_telegram_ids' => '201374791,42']);
        $initData = $this->initData(201374791, name: 'Boss');

        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($initData))->assertOk();
        $this->assertContains('owner', $me->json('data.member.roles'));

        // Owner проходит RBAC-гейт админки.
        $this->getJson('/api/v1/admin/members', $this->tgHeaders($initData))->assertOk();
    }

    public function testNonOwnerDoesNotGetOwnerRole(): void
    {
        config(['calculator.owner_telegram_ids' => '201374791']);
        $initData = $this->initData(123, name: 'Regular');

        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($initData))->assertOk();
        $this->assertSame([], $me->json('data.member.roles'));
        $this->getJson('/api/v1/admin/members', $this->tgHeaders($initData))->assertStatus(403);
    }
}
