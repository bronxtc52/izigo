<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Services\Awards\QualificationAwardService;
use Tests\TestCase;

/**
 * T10 [ПРАВА, negative-cases обязательны]: cabinet/admin роуты наград.
 * Deny-by-default: без auth 401; cabinet initData на admin 401; флаг OFF => 403
 * даже owner; read — owner,finance; mutation (mark-paid/hold/release/forfeit) —
 * owner-only; cabinet отдаёт только СВОИ награды (IDOR); каждое admin-действие — аудит.
 */
class AwardsApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private CarbonImmutable $at;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateV2Policy();
        $this->at = CarbonImmutable::parse('2026-02-01 12:00:00', 'UTC');
    }

    /** Выдать участнику награду за ранг через сервис (минуя пайплайн/флаг). */
    private function grantAward(int $memberId, StatusCode $rank): int
    {
        $this->seedRank($memberId, $rank, $this->at);

        return app(QualificationAwardService::class)->reconcileMemberFromRankHistory($memberId, $this->at)[0];
    }

    // ------------------------------------------------------------------
    // Cabinet
    // ------------------------------------------------------------------

    public function testCabinetRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        $this->getJson('/api/v1/cabinet/v2/awards', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetFlagOffBlocks(): void
    {
        [$data] = $this->registerTg(600, name: 'A');
        $this->getJson('/api/v1/cabinet/v2/awards', $this->tgHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testCabinetReturnsOwnAwardsOnly(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$aData] = $this->registerTg(600, name: 'A');
        [$bData] = $this->registerTg(601, name: 'B');
        $aId = $this->memberByTg(600)->id;
        $bId = $this->memberByTg(601)->id;
        $this->grantAward($aId, StatusCode::MANAGER);
        $this->grantAward($bId, StatusCode::DIRECTOR);

        $resp = $this->getJson('/api/v1/cabinet/v2/awards', $this->tgHeaders($aData))->assertOk();
        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonPath('data.0.award_code', 'MANAGER');
        $resp->assertJsonPath('data.0.amount_cents', 10000);
    }

    // ------------------------------------------------------------------
    // Admin — deny-by-default
    // ------------------------------------------------------------------

    public function testAdminRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        $this->getJson('/api/v1/admin/v2/awards', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testAdminCabinetInitDataRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$data] = $this->registerTg(600, name: 'A');
        // initData (cabinet) не проходит web.admin (нужен Sanctum Bearer).
        $this->getJson('/api/v1/admin/v2/awards', $this->tgHeaders($data))
            ->assertStatus(401);
    }

    public function testAdminFlagOffBlocksEvenOwner(): void
    {
        [$data] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $this->getJson('/api/v1/admin/v2/awards', $this->adminHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testAdminQueueNonPrivilegedRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$xData] = $this->registerTg(601, name: 'X');
        $this->getJson('/api/v1/admin/v2/awards', $this->adminHeaders($xData))
            ->assertStatus(403);
    }

    public function testFinanceReadsQueueButCannotMarkPaid(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$oData] = $this->registerTg(600, name: 'Owner');
        [$fData] = $this->registerTg(602, name: 'Fin');
        $this->grantRole(602, 'finance');
        $ownerId = $this->memberByTg(600)->id;
        $id = $this->grantAward($ownerId, StatusCode::MANAGER);

        // finance читает очередь.
        $this->getJson('/api/v1/admin/v2/awards?status=granted', $this->adminHeaders($fData))
            ->assertOk()->assertJsonPath('data.0.id', $id);

        // finance НЕ может выплатить (owner-only).
        $this->postJson("/api/v1/admin/v2/awards/{$id}/mark-paid", [], $this->adminHeaders($fData))
            ->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // Admin — owner payout-контур + аудит
    // ------------------------------------------------------------------

    public function testOwnerMarkPaidWritesAudit(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$oData] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $ownerId = $this->memberByTg(600)->id;
        $id = $this->grantAward($ownerId, StatusCode::DIRECTOR);

        $this->postJson("/api/v1/admin/v2/awards/{$id}/mark-paid", ['note' => 'ok'], $this->adminHeaders($oData))
            ->assertOk()->assertJsonPath('data.status', 'paid_out');

        $this->assertSame('paid_out', AwardEntitlement::find($id)->status);
        $this->assertSame(1, AdminAuditLog::query()
            ->where('action', 'v2.award.mark_paid')->where('entity_id', $id)->count());
    }

    public function testOwnerHoldReleaseForfeitWithAudit(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$oData] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $ownerId = $this->memberByTg(600)->id;
        $id = $this->grantAward($ownerId, StatusCode::MANAGER);
        $headers = $this->adminHeaders($oData);

        $this->postJson("/api/v1/admin/v2/awards/{$id}/hold", ['reason' => 'wait'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->postJson("/api/v1/admin/v2/awards/{$id}/release", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'granted');
        $this->postJson("/api/v1/admin/v2/awards/{$id}/forfeit", ['reason' => 'declined'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'forfeited');

        foreach (['v2.award.hold', 'v2.award.release', 'v2.award.forfeit'] as $action) {
            $this->assertSame(1, AdminAuditLog::query()->where('action', $action)->where('entity_id', $id)->count(), $action);
        }
    }

    public function testForfeitRequiresReason(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$oData] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $ownerId = $this->memberByTg(600)->id;
        $id = $this->grantAward($ownerId, StatusCode::MANAGER);

        $this->postJson("/api/v1/admin/v2/awards/{$id}/forfeit", [], $this->adminHeaders($oData))
            ->assertStatus(422);
    }

    public function testMarkPaidOnHoldReturns409(): void
    {
        $this->enableFeatureFlags('mh_v2_awards');
        [$oData] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $ownerId = $this->memberByTg(600)->id;
        $id = $this->grantAward($ownerId, StatusCode::MANAGER);
        $headers = $this->adminHeaders($oData);

        $this->postJson("/api/v1/admin/v2/awards/{$id}/hold", ['reason' => 'x'], $headers)->assertOk();
        $this->postJson("/api/v1/admin/v2/awards/{$id}/mark-paid", [], $headers)->assertStatus(409);
    }
}
