<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Вход в ВЕБ-админку (admin.izigo.adarasoft.com) через Telegram Login Widget → Sanctum.
 * Покрывает: валидную/поддельную/просроченную подпись виджета, выдачу токена только
 * админам (с ролями), бутстрап владельца из конфига, и гейт middleware web.admin на
 * админ-эндпоинтах (нет токена/битый токен → 401; токен без ролей → 403 на RBAC).
 */
class WebAdminAuthTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Подписать поля Login Widget валидным bot-токеном (secret = SHA256(token), не HMAC). */
    private function signWidget(array $fields): array
    {
        ksort($fields);
        $pairs = [];
        foreach ($fields as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $secret = hash('sha256', $this->botToken, true);
        $fields['hash'] = hash_hmac('sha256', implode("\n", $pairs), $secret);

        return $fields;
    }

    private function widgetFor(int $tgId, ?int $authDate = null): array
    {
        return $this->signWidget([
            'id' => $tgId,
            'first_name' => "U{$tgId}",
            'username' => "u{$tgId}",
            'auth_date' => $authDate ?? time(),
        ]);
    }

    public function testValidLoginIssuesTokenForAdmin(): void
    {
        $this->registerTg(300, name: 'Owner');
        $this->grantRole(300, 'owner');

        $res = $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(300))->assertOk();
        $token = $res->json('token');
        $this->assertNotEmpty($token);
        $this->assertContains('owner', $res->json('member.roles'));

        // Токен реально пускает в админку.
        $this->getJson('/api/v1/admin/members', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function testIssuedTokenHasExpiry(): void
    {
        // TTL ограничен (не вечный bearer к денежной панели).
        config(['calculator.web_admin_token_ttl_minutes' => 720]);
        $this->registerTg(307, name: 'Owner');
        $this->grantRole(307, 'owner');

        $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(307))->assertOk();

        $token = \Laravel\Sanctum\PersonalAccessToken::where('name', 'web-admin')->firstOrFail();
        $this->assertNotNull($token->expires_at);
    }

    public function testLoginRejectedForMemberWithoutRoles(): void
    {
        $this->registerTg(301, name: 'Partner'); // без ролей

        $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(301))->assertStatus(403);
    }

    public function testForgedWidgetRejected(): void
    {
        $this->registerTg(302, name: 'Owner');
        $this->grantRole(302, 'owner');

        $fields = $this->widgetFor(302);
        $fields['hash'] = str_repeat('0', 64); // подделанная подпись

        $this->postJson('/api/v1/auth/telegram-login', $fields)->assertStatus(401);
    }

    public function testExpiredAuthDateRejected(): void
    {
        $this->registerTg(303, name: 'Owner');
        $this->grantRole(303, 'owner');

        // Подпись валидна, но auth_date слишком стар.
        config(['calculator.telegram_login_max_age' => 60]);
        $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(303, authDate: time() - 3600))
            ->assertStatus(401);
    }

    public function testEmptyBotTokenRejects(): void
    {
        $this->registerTg(304, name: 'Owner');
        $this->grantRole(304, 'owner');

        // Мисконфиг (токен не из KV) → безопасный фейл, а не «всех пускаем».
        $fields = $this->widgetFor(304);
        config(['calculator.telegram_bot_token' => '']);
        $this->postJson('/api/v1/auth/telegram-login', $fields)->assertStatus(401);
    }

    public function testOwnerBootstrapViaLoginCreatesMember(): void
    {
        // Владелец из OWNER_TELEGRAM_IDS может войти в веб без предварительного Mini App.
        config(['calculator.owner_telegram_ids' => '305,42']);

        $res = $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(305))->assertOk();
        $this->assertContains('owner', $res->json('member.roles'));
        $this->assertDatabaseHas('members', ['telegram_id' => 305]);
    }

    public function testAdminEndpointWithoutTokenRejected(): void
    {
        $this->getJson('/api/v1/admin/members', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testAdminEndpointWithBadBearerRejected(): void
    {
        $this->getJson('/api/v1/admin/members', ['Authorization' => 'Bearer not-a-real-token'])
            ->assertStatus(401);
    }

    public function testRolelessTokenForbiddenByRbac(): void
    {
        // Токен валиден (middleware пускает), но без ролей → RBAC отдаёт 403.
        $this->registerTg(306, name: 'Partner');
        $member = Member::where('telegram_id', 306)->first();
        $token = $member->createToken('test')->plainTextToken;

        $this->getJson('/api/v1/admin/members', ['Authorization' => "Bearer {$token}"])
            ->assertStatus(403);
    }

    public function testLogoutRevokesCurrentToken(): void
    {
        // G1: утёкший bearer к денежной панели должен отзываться. logout удаляет текущий токен.
        $this->registerTg(310, name: 'Owner');
        $this->grantRole(310, 'owner');
        $token = $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(310))
            ->assertOk()->json('token');
        $auth = ['Authorization' => "Bearer {$token}"];

        // Токен работает до logout.
        $this->getJson('/api/v1/admin/members', $auth)->assertOk();

        $this->postJson('/api/v1/admin/auth/logout', [], $auth)
            ->assertOk()->assertJsonPath('status', 'ok');

        // После logout тот же bearer недействителен (401).
        $this->getJson('/api/v1/admin/members', $auth)->assertStatus(401);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function testLogoutAllRevokesEveryToken(): void
    {
        // ?all=1 — выход со всех устройств (все токены участника).
        $this->registerTg(311, name: 'Owner');
        $this->grantRole(311, 'owner');
        $t1 = $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(311))
            ->assertOk()->json('token');
        $this->postJson('/api/v1/auth/telegram-login', $this->widgetFor(311))->assertOk(); // второе устройство
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->postJson('/api/v1/admin/auth/logout?all=1', [], ['Authorization' => "Bearer {$t1}"])
            ->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
