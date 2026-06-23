<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Calculator\Models\FeatureFlag;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * C3 (Block C): рантайм фиче-флаги. Покрывает: toggle меняет enabled и инвалидирует кэш,
 * isEnabled читает корректно (deny-by-default), cabinet отдаёт только активные, и RBAC —
 * не-owner (finance/support/leader) и неаутентифицированный НЕ переключают флаги.
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Owner + по одному из остальных ролей; возвращает их initData. */
    private function bootRoles(): array
    {
        [$ownerData, $ownerRef] = $this->registerTg(700, name: 'Owner');
        $this->grantRole(700, 'owner');
        [$financeData] = $this->registerTg(701, $ownerRef, 'Finance');
        $this->grantRole(701, 'finance');
        [$supportData] = $this->registerTg(702, $ownerRef, 'Support');
        $this->grantRole(702, 'support');
        [$plainData] = $this->registerTg(703, $ownerRef, 'Plain');

        return compact('ownerData', 'financeData', 'supportData', 'plainData');
    }

    public function testServiceTogglesAndInvalidatesCache(): void
    {
        $svc = app(FeatureFlagService::class);

        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false]);
        $this->assertFalse($svc->isEnabled('c1_notifications')); // прогрев кэша

        $svc->set('c1_notifications', true);
        // Кэш сброшен при изменении — следующее чтение видит новое значение.
        $this->assertTrue($svc->isEnabled('c1_notifications'));
        $this->assertSame(true, FeatureFlag::query()->where('key', 'c1_notifications')->value('enabled'));
    }

    public function testIsEnabledDenyByDefaultForUnknownKey(): void
    {
        $svc = app(FeatureFlagService::class);
        $this->assertFalse($svc->isEnabled('nonexistent_key'));
    }

    public function testSetCreatesFlagWhenMissing(): void
    {
        $svc = app(FeatureFlagService::class);
        // actorId опускаем (null) — этот тест про создание флага, не про автора;
        // updated_by → members.id (FK), несуществующего участника БД отбракует.
        $svc->set('brand_new_flag', true);

        $this->assertDatabaseHas('feature_flags', ['key' => 'brand_new_flag', 'enabled' => true]);
    }

    public function testCabinetReturnsOnlyActiveFlags(): void
    {
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => true]);
        FeatureFlag::query()->create(['key' => 'c2_helpdesk', 'enabled' => false]);

        [$plainData] = $this->registerTg(710, name: 'Cab');

        $res = $this->getJson('/api/v1/cabinet/feature-flags', $this->tgHeaders($plainData))->assertOk();
        $data = $res->json('data');

        $this->assertArrayHasKey('c1_notifications', $data);
        $this->assertTrue($data['c1_notifications']);
        $this->assertArrayNotHasKey('c2_helpdesk', $data);
    }

    public function testOwnerListsAndTogglesFlag(): void
    {
        $r = $this->bootRoles();
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false, 'description' => 'Уведомления']);

        $this->getJson('/api/v1/admin/feature-flags', $this->adminHeaders($r['ownerData']))
            ->assertOk()
            ->assertJsonPath('data.0.key', 'c1_notifications')
            ->assertJsonPath('data.0.enabled', false);

        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c1_notifications', 'enabled' => true], $this->adminHeaders($r['ownerData']))
            ->assertOk()
            ->assertJsonPath('data.0.enabled', true);

        $flag = FeatureFlag::query()->where('key', 'c1_notifications')->first();
        $this->assertTrue($flag->enabled);
        $this->assertSame($this->memberByTg(700)->id, $flag->updated_by);
    }

    public function testToggleIsAudited(): void
    {
        $r = $this->bootRoles();
        FeatureFlag::query()->create(['key' => 'c5_pii_export', 'enabled' => false]);

        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c5_pii_export', 'enabled' => true], $this->adminHeaders($r['ownerData']))
            ->assertOk();

        $log = $this->getJson('/api/v1/admin/audit-log', $this->adminHeaders($r['ownerData']))->assertOk();
        $entry = collect($log->json('data.data'))->firstWhere('action', 'feature_flag.set');
        $this->assertNotNull($entry);
        $this->assertSame('feature_flag', $entry['entity_type']);
        $this->assertSame('c5_pii_export', $entry['after']['key']);
        $this->assertTrue($entry['after']['enabled']);
    }

    public function testValidationRejectsBadPayload(): void
    {
        $r = $this->bootRoles();

        $this->postJson('/api/v1/admin/feature-flags', ['enabled' => true], $this->adminHeaders($r['ownerData']))
            ->assertStatus(422);
        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'x', 'enabled' => 'maybe'], $this->adminHeaders($r['ownerData']))
            ->assertStatus(422);
    }

    // --- RBAC negative-cases (deny-by-default) ---

    public function testFinanceCannotListOrToggle(): void
    {
        $r = $this->bootRoles();
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false]);

        $this->getJson('/api/v1/admin/feature-flags', $this->adminHeaders($r['financeData']))->assertStatus(403);
        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c1_notifications', 'enabled' => true], $this->adminHeaders($r['financeData']))
            ->assertStatus(403);

        $this->assertFalse((bool) FeatureFlag::query()->where('key', 'c1_notifications')->value('enabled'));
    }

    public function testSupportCannotToggle(): void
    {
        $r = $this->bootRoles();
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false]);

        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c1_notifications', 'enabled' => true], $this->adminHeaders($r['supportData']))
            ->assertStatus(403);
    }

    public function testPlainPartnerCannotToggle(): void
    {
        $r = $this->bootRoles();
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false]);

        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c1_notifications', 'enabled' => true], $this->adminHeaders($r['plainData']))
            ->assertStatus(403);
    }

    public function testUnauthenticatedCannotAccessAdminOrToggle(): void
    {
        FeatureFlag::query()->create(['key' => 'c1_notifications', 'enabled' => false]);

        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->getJson('/api/v1/admin/feature-flags', $headers)->assertStatus(401);
        $this->postJson('/api/v1/admin/feature-flags', ['key' => 'c1_notifications', 'enabled' => true], $headers)
            ->assertStatus(401);
        // Чтение cabinet тоже требует telegram.auth.
        $this->getJson('/api/v1/cabinet/feature-flags', $headers)->assertStatus(401);

        $this->assertFalse((bool) FeatureFlag::query()->where('key', 'c1_notifications')->value('enabled'));
    }
}
