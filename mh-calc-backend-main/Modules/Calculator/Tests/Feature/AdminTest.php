<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Role;
use Tests\TestCase;

/**
 * Админ-портал + RBAC: гейты ролей, список/поиск участников, назначение ролей,
 * настройка плана, охват лидера (видит только своё поддерево).
 */
class AdminTest extends TestCase
{
    use RefreshDatabase;

    private array $headers = ['X-Requested-With' => 'XMLHttpRequest'];

    private function register(string $email, ?string $sponsorRef = null): string
    {
        return $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'sponsor_ref' => $sponsorRef,
        ], $this->headers)->assertOk()->json('token');
    }

    private function auth(string $token): array
    {
        return array_merge($this->headers, ['CalculatorAuthToken' => $token]);
    }

    private function grantRole(string $email, string $role, ?int $scopeMemberId = null): void
    {
        $user = CalculatorUser::where('email', $email)->firstOrFail();
        $roleId = Role::where('name', $role)->value('id');
        $user->roles()->syncWithoutDetaching([$roleId => ['leader_scope_member_id' => $scopeMemberId]]);
    }

    private function refOf(string $token): string
    {
        return $this->getJson('/api/v1/cabinet/me', $this->auth($token))->json('data.member.ref_code');
    }

    public function testPartnerCannotAccessAdmin(): void
    {
        $token = $this->register('partner@t.dev');
        $this->getJson('/api/v1/admin/members', $this->auth($token))->assertStatus(403);
    }

    public function testOwnerListsAndSearchesMembers(): void
    {
        $ownerToken = $this->register('owner@t.dev');
        $this->grantRole('owner@t.dev', 'owner');
        $ref = $this->refOf($ownerToken);
        $this->register('alice@t.dev', $ref);

        $list = $this->getJson('/api/v1/admin/members', $this->auth($ownerToken))->assertOk();
        $this->assertGreaterThanOrEqual(2, $list->json('data.total'));

        $found = $this->getJson('/api/v1/admin/members?search=alice', $this->auth($ownerToken))->assertOk();
        $this->assertSame(1, $found->json('data.total'));
    }

    public function testOwnerAssignsRoleAndGrantsAccess(): void
    {
        $ownerToken = $this->register('o2@t.dev');
        $this->grantRole('o2@t.dev', 'owner');
        $ref = $this->refOf($ownerToken);
        $supportToken = $this->register('support@t.dev', $ref);
        $supportMemberId = Member::whereHas('user', fn ($u) => $u->where('email', 'support@t.dev'))->value('id');

        // До назначения роли — нет доступа.
        $this->getJson('/api/v1/admin/members', $this->auth($supportToken))->assertStatus(403);

        // Owner назначает роль support.
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'support'], $this->auth($ownerToken))
            ->assertOk()->assertJsonPath('data.roles.0', 'support');

        // Теперь support видит участников, но не может назначать роли.
        $this->getJson('/api/v1/admin/members', $this->auth($supportToken))->assertOk();
        $this->postJson("/api/v1/admin/members/{$supportMemberId}/role", ['role' => 'owner'], $this->auth($supportToken))
            ->assertStatus(403);
    }

    public function testPlanSettingsEditableByOwnerOnly(): void
    {
        $ownerToken = $this->register('o3@t.dev');
        $this->grantRole('o3@t.dev', 'owner');
        $ref = $this->refOf($ownerToken);
        $financeToken = $this->register('fin@t.dev', $ref);
        $this->grantRole('fin@t.dev', 'finance');

        // Owner меняет режим размещения.
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'manual'], $this->auth($ownerToken))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');

        // Finance видит, но не может менять.
        $this->getJson('/api/v1/admin/plan-settings', $this->auth($financeToken))
            ->assertOk()->assertJsonPath('data.placement_mode', 'manual');
        $this->putJson('/api/v1/admin/plan-settings', ['placement_mode' => 'auto'], $this->auth($financeToken))
            ->assertStatus(403);
    }

    public function testLeaderSeesOnlyOwnSubtree(): void
    {
        $ownerToken = $this->register('root@t.dev');
        $this->grantRole('root@t.dev', 'owner');
        $rootRef = $this->refOf($ownerToken);

        $leaderToken = $this->register('leader@t.dev', $rootRef);      // под root (левая нога)
        $leaderRef = $this->refOf($leaderToken);
        $this->register('downline@t.dev', $leaderRef);                 // под лидером
        $this->register('other@t.dev', $rootRef);                      // под root (правая нога), не под лидером

        $leaderMemberId = Member::whereHas('user', fn ($u) => $u->where('email', 'leader@t.dev'))->value('id');
        $this->grantRole('leader@t.dev', 'leader', $leaderMemberId);

        $list = $this->getJson('/api/v1/admin/members', $this->auth($leaderToken))->assertOk();
        $names = collect($list->json('data.data'))->pluck('name')->all();

        $this->assertContains('leader@t.dev', $names);   // имя = email (first_name пуст)
        $this->assertContains('downline@t.dev', $names);
        $this->assertNotContains('root@t.dev', $names);
        $this->assertNotContains('other@t.dev', $names);
    }

    public function testLeaderScopeExcludesSpilloverStranger(): void
    {
        // Охват лидера = спонсорская линия, НЕ placement. Чужой партнёр, заспилловеренный
        // под лидера по дереву размещения, но приглашённый владельцем, виден НЕ должен.
        $ownerToken = $this->register('sr@t.dev');
        $this->grantRole('sr@t.dev', 'owner');
        $rootRef = $this->refOf($ownerToken);

        $leaderToken = $this->register('sl@t.dev', $rootRef);  // R.left
        $this->register('sm@t.dev', $rootRef);                 // R.right (заполняет ноги R)
        $this->register('sw@t.dev', $rootRef);                 // спилловер под лидера, но спонсор — R
        $leaderRef = $this->refOf($leaderToken);
        $this->register('sd@t.dev', $leaderRef);               // личник лидера (sponsor = L)

        $leaderMemberId = Member::whereHas('user', fn ($u) => $u->where('email', 'sl@t.dev'))->value('id');
        $this->grantRole('sl@t.dev', 'leader', $leaderMemberId);

        $names = collect(
            $this->getJson('/api/v1/admin/members', $this->auth($leaderToken))->assertOk()->json('data.data')
        )->pluck('name')->all();

        $this->assertContains('sl@t.dev', $names);
        $this->assertContains('sd@t.dev', $names);          // личник лидера — виден
        $this->assertNotContains('sw@t.dev', $names);       // чужой спилловер — НЕ виден
        $this->assertNotContains('sm@t.dev', $names);
    }
}
