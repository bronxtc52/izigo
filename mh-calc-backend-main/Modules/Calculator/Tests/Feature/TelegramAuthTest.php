<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Авторизация платформы — ТОЛЬКО Telegram initData. Идентичность: участник (member),
 * лид (перешёл по рефке, ещё не купил) или никто (нужна реф-ссылка). Участник создаётся
 * при первой покупке (промоушн лида), НЕ при первом заходе. Подделка/пустая подпись → 401.
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

    public function testFirstVisitWithSponsorCreatesLeadNotMember(): void
    {
        [, $ref] = $this->registerTg(100); // спонсор-корень (участник)

        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(101, $ref)))->assertOk();

        $this->assertTrue($res->json('data.is_lead'));
        $this->assertSame($ref, $res->json('data.sponsor.ref_code'));
        $this->assertDatabaseHas('leads', ['telegram_id' => 101]);
        $this->assertDatabaseMissing('members', ['telegram_id' => 101]);
    }

    public function testFirstVisitWithoutSponsorNeedsReferral(): void
    {
        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(202)))->assertOk();

        $this->assertTrue($res->json('data.need_referral'));
        $this->assertDatabaseMissing('leads', ['telegram_id' => 202]);
        $this->assertDatabaseMissing('members', ['telegram_id' => 202]);
    }

    public function testExistingMemberResolvedAsMember(): void
    {
        $this->registerTg(303);

        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(303)))->assertOk();

        $this->assertNotEmpty($res->json('data.member.ref_code'));
        $this->assertNull($res->json('data.is_lead'));
    }

    public function testReattachLastClickWins(): void
    {
        [, $refA] = $this->registerTg(100);
        [, $refB] = $this->registerTg(200);

        // Лид под спонсором A, затем заход по рефке B → перепривязка к B.
        $this->makeLead(101, $refA);
        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(101, $refB)))->assertOk();

        $this->assertSame($refB, $res->json('data.sponsor.ref_code'));
        $sponsorB = Member::where('telegram_id', 200)->first();
        $this->assertSame($sponsorB->id, Lead::where('telegram_id', 101)->value('sponsor_id'));
        $this->assertSame(1, Lead::where('telegram_id', 101)->count());
    }

    public function testForgedInitDataRejected(): void
    {
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

    public function testStaleInitDataRejectedByTightenedWindow(): void
    {
        // G1: окно replay initData сужено до 1ч по умолчанию — подпись валидна, но auth_date
        // старше часа → 401 (раньше при 24ч такой initData ещё принимался).
        $this->registerTg(404);
        $stale = $this->signInitData([
            'user' => json_encode(['id' => 404, 'first_name' => 'U404', 'username' => 'u404']),
            'auth_date' => time() - 4000, // > дефолтных 3600
            'query_id' => 'AAA',
        ]);

        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($stale))->assertStatus(401);
    }

    public function testEmptyBotTokenRejects(): void
    {
        config(['calculator.telegram_bot_token' => '']);
        $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(999)))->assertStatus(401);
    }

    public function testConcurrentResolveReusesMember(): void
    {
        // Участник уже создан параллельным запросом — повторный резолв возвращает его.
        Member::create([
            'telegram_id' => 888,
            'name' => 'tg:888',
            'ref_code' => 'TGRACE01',
            'status' => 'registered',
            'path' => '1',
        ]);

        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(888)))->assertOk();
        $this->assertNotEmpty($res->json('data.member.ref_code'));
        $this->assertSame(1, Member::where('telegram_id', 888)->count());
    }

    public function testActivatePackageIsMemberOnly(): void
    {
        // Участник может (пере)активировать пакет напрямую.
        [$initData] = $this->registerTg(777);
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($initData))
            ->assertOk();
        $this->assertDatabaseHas('members', ['telegram_id' => 777, 'status' => 'active', 'package_id' => 1]);

        // Лид (ещё не купил) активировать через этот эндпоинт НЕ может (member-only → 404).
        [, $ref] = $this->registerTg(100);
        [$leadInit] = $this->makeLead(110, $ref);
        $this->postJson('/api/v1/cabinet/activate-package', ['package_id' => 1], $this->tgHeaders($leadInit))
            ->assertStatus(404);
    }

    public function testOwnerBootstrapForExistingMember(): void
    {
        config(['calculator.owner_telegram_ids' => '201374791,42']);
        $this->registerTg(201374791, name: 'Boss'); // owner как корень-участник

        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(201374791, name: 'Boss')))->assertOk();
        $this->assertContains('owner', $me->json('data.member.roles'));

        $this->getJson('/api/v1/admin/members', $this->adminHeaders($this->initData(201374791, name: 'Boss')))->assertOk();
    }

    public function testNonOwnerDoesNotGetOwnerRole(): void
    {
        config(['calculator.owner_telegram_ids' => '201374791']);
        [, $ref] = $this->registerTg(201374791, name: 'Boss');
        [$initData] = $this->registerTg(123, $ref, 'Regular');

        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($initData))->assertOk();
        $this->assertSame([], $me->json('data.member.roles'));
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($initData))->assertStatus(403);
    }
}
