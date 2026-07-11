<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Models\PolicyVersion;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Tests\TestCase;

/**
 * T01: админ-API версий политики V2 (/api/v1/admin/v2/policy-versions).
 * Обязательные negative-cases прав (деньги + security): без токена 401, не-owner 403,
 * finance read-only, telegram-токен на admin-роуте 401, флаг OFF 403 (deny-by-default);
 * плюс аудит и API-семантика активации.
 */
class PolicyVersionAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BASE = '/api/v1/admin/v2/policy-versions';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** owner + finance + рядовой участник; флаг mh_plan_v2_admin включён. */
    private function boot(): array
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$ownerData, $ownerRef] = $this->registerTg(400, name: 'Owner');
        $this->grantRole(400, 'owner');
        [$financeData] = $this->registerTg(401, $ownerRef, 'Finance');
        $this->grantRole(401, 'finance');
        [$memberData] = $this->registerTg(402, $ownerRef, 'Plain');

        return [$ownerData, $financeData, $memberData];
    }

    private function seededId(): int
    {
        return PolicyVersion::query()->where('code', DefaultPolicyConfig::CODE)->firstOrFail()->id;
    }

    // --- права (negative обязательные) ---

    public function testUnauthenticatedGets401(): void
    {
        $this->boot();
        $this->getJson(self::BASE)->assertStatus(401);
        $this->postJson(self::BASE, [])->assertStatus(401);
    }

    public function testTelegramInitDataTokenIsNotAdminAuth(): void
    {
        [$ownerData] = $this->boot();
        // Заголовки Mini App (initData) вместо Bearer web-токена -> 401 (web.admin).
        $this->getJson(self::BASE, $this->tgHeaders($ownerData))->assertStatus(401);
    }

    public function testPlainMemberGets403(): void
    {
        [, , $memberData] = $this->boot();
        $this->getJson(self::BASE, $this->adminHeaders($memberData))->assertStatus(403);
        $this->postJson(self::BASE . '/' . $this->seededId() . '/activate', [], $this->adminHeaders($memberData))
            ->assertStatus(403);
    }

    public function testFinanceCanReadButNotMutate(): void
    {
        [, $financeData] = $this->boot();
        $headers = $this->adminHeaders($financeData);

        $this->getJson(self::BASE, $headers)->assertOk();
        $this->getJson(self::BASE . '/' . $this->seededId(), $headers)->assertOk();

        $this->postJson(self::BASE, ['code' => 'x', 'config' => []], $headers)->assertStatus(403);
        $this->putJson(self::BASE . '/' . $this->seededId(), ['config' => []], $headers)->assertStatus(403);
        $this->postJson(self::BASE . '/' . $this->seededId() . '/activate', [], $headers)->assertStatus(403);
        $this->postJson(self::BASE . '/' . $this->seededId() . '/retire', [], $headers)->assertStatus(403);
    }

    public function testFeatureFlagOffDeniesByDefault(): void
    {
        // Флаг НЕ включаем: deny-by-default даже для owner.
        [$ownerData, $ownerRef] = $this->registerTg(400, name: 'Owner');
        $this->grantRole(400, 'owner');
        unset($ownerRef);

        $this->getJson(self::BASE, $this->adminHeaders($ownerData))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    // --- happy-path и API-семантика ---

    public function testIndexShowsSeededDraftWithoutConfigBody(): void
    {
        [$ownerData] = $this->boot();

        $res = $this->getJson(self::BASE, $this->adminHeaders($ownerData))->assertOk();
        $row = collect($res->json('data'))->firstWhere('code', DefaultPolicyConfig::CODE);
        $this->assertNotNull($row);
        $this->assertSame('draft', $row['status']);
        $this->assertArrayNotHasKey('config', $row);
        $this->assertSame(DefaultPolicyConfig::canonicalHash(DefaultPolicyConfig::doc()), $row['config_hash']);
    }

    public function testShowReturnsFullConfig(): void
    {
        [$ownerData] = $this->boot();

        $res = $this->getJson(self::BASE . '/' . $this->seededId(), $this->adminHeaders($ownerData))->assertOk();
        $this->assertSame(DefaultPolicyConfig::doc(), $res->json('data.config'));
    }

    public function testStoreDraftValidatesConfig(): void
    {
        [$ownerData] = $this->boot();
        $headers = $this->adminHeaders($ownerData);

        $broken = DefaultPolicyConfig::doc();
        $broken['rank_forever'] = false;
        $this->postJson(self::BASE, ['code' => 'mh-v2-usd-t', 'config' => $broken], $headers)
            ->assertStatus(422);

        $this->postJson(self::BASE, ['code' => 'mh-v2-usd-t', 'config' => DefaultPolicyConfig::doc()], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        // Дубль кода -> 422.
        $this->postJson(self::BASE, ['code' => 'mh-v2-usd-t', 'config' => DefaultPolicyConfig::doc()], $headers)
            ->assertStatus(422);
    }

    public function testActivateFlowClosesPreviousAndResolves(): void
    {
        [$ownerData] = $this->boot();
        $headers = $this->adminHeaders($ownerData);
        $v1 = $this->seededId();

        $this->postJson(self::BASE . "/{$v1}/activate", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // resolve: сейчас действует v1.
        $this->getJson(self::BASE . '/resolve', $headers)
            ->assertOk()
            ->assertJsonPath('data.policy_version_id', $v1)
            ->assertJsonPath('data.code', DefaultPolicyConfig::CODE);

        // Вторая версия активацией в будущее закрывает первую.
        $v2 = $this->postJson(self::BASE, ['code' => 'mh-v2-usd-2', 'config' => DefaultPolicyConfig::doc()], $headers)
            ->assertOk()->json('data.id');
        $futureFrom = now()->addDays(3)->toIso8601String();
        $this->postJson(self::BASE . "/{$v2}/activate", ['valid_from' => $futureFrom], $headers)->assertOk();

        $v1Row = PolicyVersion::query()->findOrFail($v1);
        $this->assertSame(PolicyVersion::STATUS_RETIRED, $v1Row->status);
        $this->assertNotNull($v1Row->valid_to);

        // resolve по датам: сейчас — v1 (интервал ещё его), после futureFrom — v2.
        $this->getJson(self::BASE . '/resolve?at=' . urlencode(now()->toIso8601String()), $headers)
            ->assertOk()->assertJsonPath('data.policy_version_id', $v1);
        $this->getJson(self::BASE . '/resolve?at=' . urlencode(now()->addDays(4)->toIso8601String()), $headers)
            ->assertOk()->assertJsonPath('data.policy_version_id', $v2);

        // updateDraft на active -> 422; активация активной -> 422.
        $this->putJson(self::BASE . "/{$v2}", ['config' => DefaultPolicyConfig::doc()], $headers)->assertStatus(422);
        $this->postJson(self::BASE . "/{$v2}/activate", [], $headers)->assertStatus(422);
    }

    public function testRetroValidFromRejectedWithoutFlag(): void
    {
        [$ownerData] = $this->boot();

        $this->postJson(
            self::BASE . '/' . $this->seededId() . '/activate',
            ['valid_from' => now()->subDays(2)->toIso8601String()],
            $this->adminHeaders($ownerData),
        )->assertStatus(422);
    }

    public function testResolveWithoutActiveVersionIs404(): void
    {
        [$ownerData] = $this->boot();
        $this->getJson(self::BASE . '/resolve', $this->adminHeaders($ownerData))->assertStatus(404);
    }

    public function testMutationsAreAudited(): void
    {
        [$ownerData] = $this->boot();
        $headers = $this->adminHeaders($ownerData);
        $ownerId = $this->memberByTg(400)->id;

        $id = $this->postJson(self::BASE, ['code' => 'mh-v2-usd-a', 'config' => DefaultPolicyConfig::doc()], $headers)
            ->assertOk()->json('data.id');
        $changed = DefaultPolicyConfig::doc();
        $changed['grace']['client_to_consultant_days'] = 45;
        $this->putJson(self::BASE . "/{$id}", ['config' => $changed], $headers)->assertOk();
        $this->postJson(self::BASE . "/{$id}/activate", [], $headers)->assertOk();
        $this->postJson(self::BASE . "/{$id}/retire", [], $headers)->assertOk();

        $actions = AdminAuditLog::query()
            ->where('entity_type', 'policy_version')
            ->where('entity_id', $id)
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['policy_version.create', 'policy_version.update', 'policy_version.activate', 'policy_version.retire'],
            $actions->pluck('action')->all(),
        );
        foreach ($actions as $entry) {
            $this->assertSame($ownerId, $entry->actor_member_id);
        }

        // before/after: активация зафиксировала переход draft -> active.
        $activate = $actions->firstWhere('action', 'policy_version.activate');
        $this->assertSame('draft', $activate->before['status']);
        $this->assertSame('active', $activate->after['status']);
    }
}
