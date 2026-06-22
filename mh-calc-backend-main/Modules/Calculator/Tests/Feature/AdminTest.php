<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Админ-портал + RBAC (Telegram-идентичность): гейты ролей, список/поиск участников,
 * назначение ролей, настройка плана, охват лидера (видит только своё поддерево).
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    public function testPartnerCannotAccessAdmin(): void
    {
        [$initData] = $this->registerTg(100, name: 'Partner');
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($initData))->assertStatus(403);
    }

    public function testOwnerListsAndSearchesMembers(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(110, name: 'Owner');
        $this->grantRole(110, 'owner');
        $this->registerTg(111, $ownerRef, 'Alice');

        $list = $this->getJson('/api/v1/admin/members', $this->adminHeaders($ownerData))->assertOk();
        $this->assertGreaterThanOrEqual(2, $list->json('data.total'));

        $found = $this->getJson('/api/v1/admin/members?search=Alice', $this->adminHeaders($ownerData))->assertOk();
        $this->assertSame(1, $found->json('data.total'));
    }

    public function testOwnerAssignsRoleAndGrantsAccess(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(120, name: 'Owner');
        $this->grantRole(120, 'owner');
        [$supportData] = $this->registerTg(121, $ownerRef, 'Support');
        $supportMemberId = $this->memberByTg(121)->id;

        // До назначения роли — нет доступа.
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($supportData))->assertStatus(403);

        // Owner назначает роль support.
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'support'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.roles.0', 'support');

        // Теперь support видит участников, но не может назначать роли.
        $this->getJson('/api/v1/admin/members', $this->adminHeaders($supportData))->assertOk();
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'owner'], $this->adminHeaders($supportData))
            ->assertStatus(403);
    }

    public function testPlanSettingsEditableByOwnerOnly(): void
    {
        [$ownerData, $ownerRef] = $this->registerTg(130, name: 'Owner');
        $this->grantRole(130, 'owner');
        [$financeData] = $this->registerTg(131, $ownerRef, 'Finance');
        $this->grantRole(131, 'finance');

        // Owner меняет режим размещения.
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'manual'], $this->adminHeaders($ownerData))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');

        // Finance видит, но не может менять.
        $this->getJson('/api/v1/admin/plan-settings', $this->adminHeaders($financeData))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'auto'], $this->adminHeaders($financeData))
            ->assertStatus(403);
    }

    public function testLeaderSeesOnlyOwnSubtree(): void
    {
        [$ownerData, $rootRef] = $this->registerTg(140, name: 'RootOwner');
        $this->grantRole(140, 'owner');

        [$leaderData, $leaderRef] = $this->registerTg(141, $rootRef, 'Leader');   // под root (левая нога)
        $this->registerTg(142, $leaderRef, 'Downline');                           // под лидером
        $this->registerTg(143, $rootRef, 'Other');                                // под root (правая нога), не под лидером

        $this->grantRole(141, 'leader', $this->memberByTg(141)->id);

        $list = $this->getJson('/api/v1/admin/members', $this->adminHeaders($leaderData))->assertOk();
        $names = collect($list->json('data.data'))->pluck('name')->all();

        $this->assertContains('Leader', $names);
        $this->assertContains('Downline', $names);
        $this->assertNotContains('RootOwner', $names);
        $this->assertNotContains('Other', $names);
    }

    public function testLeaderScopeExcludesSpilloverStranger(): void
    {
        // Охват лидера = спонсорская линия, НЕ placement. Чужой партнёр, заспилловеренный
        // под лидера по дереву размещения, но приглашённый владельцем, виден НЕ должен.
        [, $rootRef] = $this->registerTg(150, name: 'SRoot');
        $this->grantRole(150, 'owner');

        [$leaderData, $leaderRef] = $this->registerTg(151, $rootRef, 'SLeader');  // R.left
        $this->registerTg(152, $rootRef, 'SMid');                                 // R.right (заполняет ноги R)
        $this->registerTg(153, $rootRef, 'SStranger');                            // спилловер под лидера, спонсор — R
        $this->registerTg(154, $leaderRef, 'SDownline');                          // личник лидера (sponsor = L)

        $this->grantRole(151, 'leader', $this->memberByTg(151)->id);

        $names = collect(
            $this->getJson('/api/v1/admin/members', $this->adminHeaders($leaderData))->assertOk()->json('data.data')
        )->pluck('name')->all();

        $this->assertContains('SLeader', $names);
        $this->assertContains('SDownline', $names);          // личник лидера — виден
        $this->assertNotContains('SStranger', $names);       // чужой спилловер — НЕ виден
        $this->assertNotContains('SMid', $names);
    }
}
