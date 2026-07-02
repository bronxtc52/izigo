<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C6 (Block C) — со-партнёры / наследники в профиле.
 *
 * Справочные данные: партнёр CRUD-ит ТОЛЬКО свои записи (несколько разрешено, сумма
 * долей не валидируется); чужую запись править/удалять нельзя (404). Админка READ-ONLY
 * (просмотр owner/finance/support; write-роутов нет). Без auth → 401. На деньги/дерево
 * не влияет.
 */
class CopartnerTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->enableFeatureFlags('c6_copartners');
    }

    // --- Cabinet CRUD (свои записи) ---

    public function testPartnerCanCreateAndListOwnCopartners(): void
    {
        [$aData] = $this->registerTg(500, name: 'A');

        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'Иван Петров', 'phone' => '+70000000001', 'share_percent' => 50],
            $this->tgHeaders($aData))->assertStatus(201)->assertJsonPath('data.full_name', 'Иван Петров');

        $res = $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($aData))->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('copartner', $res->json('data.0.kind'));
    }

    public function testMultipleRecordsAllowedWithoutShareSumValidation(): void
    {
        [$aData] = $this->registerTg(501, name: 'A');

        // Несколько записей, суммарная доля > 100% — должно пройти (сумма НЕ валидируется).
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'Первый', 'share_percent' => 80],
            $this->tgHeaders($aData))->assertStatus(201);
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'heir', 'full_name' => 'Второй', 'share_percent' => 70],
            $this->tgHeaders($aData))->assertStatus(201);

        $res = $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($aData))->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function testPartnerCanUpdateAndDeleteOwnRecord(): void
    {
        [$aData] = $this->registerTg(502, name: 'A');
        $id = $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'Старое имя'],
            $this->tgHeaders($aData))->json('data.id');

        $this->putJson("/api/v1/cabinet/copartners/{$id}",
            ['kind' => 'heir', 'full_name' => 'Новое имя'],
            $this->tgHeaders($aData))->assertOk()->assertJsonPath('data.full_name', 'Новое имя')
            ->assertJsonPath('data.kind', 'heir');

        $this->deleteJson("/api/v1/cabinet/copartners/{$id}", [], $this->tgHeaders($aData))->assertOk();
        $this->assertCount(0, $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($aData))->json('data'));
    }

    // --- Negative: scope (только свои) ---

    public function testPartnerSeesOnlyOwnRecords(): void
    {
        [$aData] = $this->registerTg(510, name: 'A');
        [$bData] = $this->registerTg(511, name: 'B');
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'Запись A'], $this->tgHeaders($aData))->assertStatus(201);

        $res = $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($bData))->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function testCannotUpdateOthersRecord(): void
    {
        [$aData] = $this->registerTg(520, name: 'A');
        [$bData] = $this->registerTg(521, name: 'B');
        $aId = $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'A owns'], $this->tgHeaders($aData))->json('data.id');

        // B пытается изменить запись A → 404 (не раскрываем существование).
        $this->putJson("/api/v1/cabinet/copartners/{$aId}",
            ['kind' => 'heir', 'full_name' => 'Hacked'], $this->tgHeaders($bData))->assertStatus(404);

        // Запись A не изменилась.
        $this->assertSame('A owns',
            $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($aData))->json('data.0.full_name'));
    }

    public function testCannotDeleteOthersRecord(): void
    {
        [$aData] = $this->registerTg(530, name: 'A');
        [$bData] = $this->registerTg(531, name: 'B');
        $aId = $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'A owns'], $this->tgHeaders($aData))->json('data.id');

        $this->deleteJson("/api/v1/cabinet/copartners/{$aId}", [], $this->tgHeaders($bData))->assertStatus(404);
        // Запись A на месте.
        $this->assertCount(1, $this->getJson('/api/v1/cabinet/copartners', $this->tgHeaders($aData))->json('data'));
    }

    // --- Validation ---

    public function testValidationRejectsBadKindAndMissingName(): void
    {
        [$aData] = $this->registerTg(540, name: 'A');

        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'spouse', 'full_name' => 'X'], $this->tgHeaders($aData))->assertStatus(422);
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner'], $this->tgHeaders($aData))->assertStatus(422);
    }

    // --- Auth ---

    public function testCabinetRequiresAuth(): void
    {
        $this->getJson('/api/v1/cabinet/copartners', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'copartner', 'full_name' => 'X'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    // --- Admin read-only ---

    public function testAdminCanViewMemberCopartners(): void
    {
        [$ownerData] = $this->registerTg(550, name: 'Owner');
        $this->grantRole(550, 'owner');
        [$pData] = $this->registerTg(551, name: 'Partner');
        $this->postJson('/api/v1/cabinet/copartners',
            ['kind' => 'heir', 'full_name' => 'Наследник П'], $this->tgHeaders($pData))->assertStatus(201);

        $partnerId = $this->memberByTg(551)->id;
        $res = $this->getJson("/api/v1/admin/members/{$partnerId}/copartners", $this->adminHeaders($ownerData))
            ->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('Наследник П', $res->json('data.0.full_name'));
    }

    public function testFinanceAndSupportCanViewButLeaderCannot(): void
    {
        [$pData] = $this->registerTg(560, name: 'Partner');
        $partnerId = $this->memberByTg(560)->id;

        [$finData] = $this->registerTg(561, name: 'Finance');
        $this->grantRole(561, 'finance');
        $this->getJson("/api/v1/admin/members/{$partnerId}/copartners", $this->adminHeaders($finData))->assertOk();

        [$supData] = $this->registerTg(562, name: 'Support');
        $this->grantRole(562, 'support');
        $this->getJson("/api/v1/admin/members/{$partnerId}/copartners", $this->adminHeaders($supData))->assertOk();

        [$leadData] = $this->registerTg(563, name: 'Leader');
        $this->grantRole(563, 'leader');
        $this->getJson("/api/v1/admin/members/{$partnerId}/copartners", $this->adminHeaders($leadData))
            ->assertStatus(403);
    }

    public function testAdminViewRequiresAuth(): void
    {
        $this->getJson('/api/v1/admin/members/1/copartners', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testNoAdminWriteRoutesExist(): void
    {
        // Контракт Gate-A п.16: админка read-only. POST/PUT/DELETE на admin-эндпоинт
        // не зарегистрированы → 405 (метод не разрешён) или 404, но НЕ 200/201.
        [$ownerData] = $this->registerTg(570, name: 'Owner');
        $this->grantRole(570, 'owner');
        $partnerId = $this->memberByTg(570)->id;

        $post = $this->postJson("/api/v1/admin/members/{$partnerId}/copartners",
            ['kind' => 'copartner', 'full_name' => 'X'], $this->adminHeaders($ownerData));
        $this->assertContains($post->getStatusCode(), [404, 405]);

        $put = $this->putJson("/api/v1/admin/members/{$partnerId}/copartners/1",
            ['kind' => 'copartner', 'full_name' => 'X'], $this->adminHeaders($ownerData));
        $this->assertContains($put->getStatusCode(), [404, 405]);
    }
}
