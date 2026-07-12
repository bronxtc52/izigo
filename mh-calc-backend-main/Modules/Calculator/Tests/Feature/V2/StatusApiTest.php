<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Tests\TestCase;

/**
 * T05 [ПРАВА, negative-cases обязательны]: cabinet/admin статус-роуты.
 * Deny-by-default: без auth 401; cabinet initData на admin 401; флаг OFF => 403
 * даже owner; не-owner/finance 403; recompute owner-only + аудит; IDOR — cabinet
 * отдаёт только СВОЙ статус.
 */
class StatusApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
    }

    public function testCabinetRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        $this->getJson('/api/v1/cabinet/v2/status', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetFlagOffBlocks(): void
    {
        [$rootData] = $this->registerTg(600, name: 'Root');
        // Флаг mh_v2_statuses OFF => 403 FEATURE_DISABLED.
        $this->getJson('/api/v1/cabinet/v2/status', $this->tgHeaders($rootData))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testCabinetReturnsOwnStatusOnly(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        [$rootData] = $this->registerTg(600, name: 'Root');
        $root = $this->memberByTg(600);
        $this->seedPartnerState($root->id, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);
        $this->seedRank($root->id, StatusCode::CONSULTANT);

        // Роут без параметра id — участник берётся из auth (анти-IDOR структурно).
        $resp = $this->getJson('/api/v1/cabinet/v2/status', $this->tgHeaders($rootData))->assertOk();
        $resp->assertJsonPath('data.state', PartnerState::STATE_CONSULTANT);
        $resp->assertJsonPath('data.current_rank_code', 'CONSULTANT');

        $this->getJson('/api/v1/cabinet/v2/status/ranks', $this->tgHeaders($rootData))
            ->assertOk()->assertJsonPath('data.0.rank_code', 'CONSULTANT');
    }

    public function testAdminRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        $this->getJson('/api/v1/admin/v2/statuses/1', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testAdminCabinetInitDataRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        [$rootData] = $this->registerTg(600, name: 'Root');
        $id = $this->memberByTg(600)->id;
        // initData (cabinet) не проходит web.admin (нужен Sanctum Bearer).
        $this->getJson("/api/v1/admin/v2/statuses/{$id}", $this->tgHeaders($rootData))
            ->assertStatus(401);
    }

    public function testAdminFlagOffBlocksEvenOwner(): void
    {
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');
        $id = $this->memberByTg(600)->id;
        $this->getJson("/api/v1/admin/v2/statuses/{$id}", $this->adminHeaders($rootData))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testAdminNonPrivilegedRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        [$rootData] = $this->registerTg(600, name: 'Root');
        [$xData] = $this->registerTg(601, name: 'X');
        $id = $this->memberByTg(600)->id;
        // Участник без owner/finance роли — 403.
        $this->getJson("/api/v1/admin/v2/statuses/{$id}", $this->adminHeaders($xData))
            ->assertStatus(403);
    }

    public function testFinanceReadsButCannotRecompute(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        [$rootData] = $this->registerTg(600, name: 'Root');
        [$fData] = $this->registerTg(602, name: 'Fin');
        $this->grantRole(602, 'finance');
        $root = $this->memberByTg(600);
        $this->seedPartnerState($root->id, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);

        $headers = $this->adminHeaders($fData);
        $this->getJson("/api/v1/admin/v2/statuses/{$root->id}", $headers)->assertOk();
        $this->getJson("/api/v1/admin/v2/statuses/{$root->id}/evaluations", $headers)->assertOk();

        // recompute — owner-only.
        $this->postJson("/api/v1/admin/v2/statuses/{$root->id}/recompute", [], $headers)
            ->assertStatus(403);
    }

    public function testOwnerRecomputeWritesAudit(): void
    {
        $this->enableFeatureFlags('mh_v2_statuses');
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');
        $root = $this->memberByTg(600);
        $this->seedPartnerState($root->id, PartnerState::STATE_CONSULTANT, StatusCode::CONSULTANT);

        $this->postJson("/api/v1/admin/v2/statuses/{$root->id}/recompute", [], $this->adminHeaders($rootData))
            ->assertOk()->assertJsonPath('data.member_id', $root->id);

        $this->assertSame(1, AdminAuditLog::query()
            ->where('action', 'v2.status.recompute')->where('entity_id', $root->id)->count());
    }
}
