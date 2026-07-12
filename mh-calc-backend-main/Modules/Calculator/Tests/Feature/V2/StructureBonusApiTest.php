<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Models\StructureBonus;
use Tests\TestCase;

/**
 * T06 [ПРАВА, negative-cases обязательны]: cabinet/admin роуты структурной премии.
 * Deny-by-default: без auth 401; cabinet initData на admin 401; флаг OFF => 403
 * FEATURE_DISABLED даже owner; не-owner/finance 403; IDOR — cabinet отдаёт только
 * СВОИ строки (member из auth, чужой id не принимается).
 */
class StructureBonusApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function seedBonus(int $memberId, int $periodId, int $net = 450): StructureBonus
    {
        return StructureBonus::query()->create([
            'period_id' => $periodId,
            'member_id' => $memberId,
            'policy_version_id' => 1,
            'rank_code' => 'MANAGER',
            'rate_bps' => 500,
            'matched_pv' => '90',
            'matched_bv_cents' => 9000,
            'gross_cents' => $net,
            'half_cap_cents' => 50000,
            'monthly_cap_cents' => 100000,
            'cap_remaining_before_cents' => 100000,
            'after_cap_cents' => $net,
            'forfeited_cents' => 0,
            'net_cents' => $net,
            'accrual_month' => '2026-07',
            'status' => StructureBonus::STATUS_POSTED,
            'posting_idempotency_key' => "v2:structure:{$periodId}:{$memberId}",
            'explanation' => ['rate_bps' => 500],
        ]);
    }

    // ------------------------------------------------------------------
    // Cabinet
    // ------------------------------------------------------------------

    public function testCabinetRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        $this->getJson('/api/v1/cabinet/v2/structure-bonus', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetFlagOffBlocks(): void
    {
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->getJson('/api/v1/cabinet/v2/structure-bonus', $this->tgHeaders($rootData))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testCabinetReturnsOwnRowsOnly(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$rootData] = $this->registerTg(600, name: 'Root');
        [$xData] = $this->registerTg(601, name: 'X');
        $root = $this->memberByTg(600);
        $other = $this->memberByTg(601);

        $this->seedBonus($root->id, 1, 450);
        $this->seedBonus($other->id, 1, 999); // чужая строка — не должна утечь

        $resp = $this->getJson('/api/v1/cabinet/v2/structure-bonus', $this->tgHeaders($rootData))->assertOk();
        $resp->assertJsonCount(1, 'data');
        $resp->assertJsonPath('data.0.net_cents', 450);
        // Чужой net не появляется.
        $this->assertNotContains(999, array_column($resp->json('data'), 'net_cents'));
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function testAdminRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/1', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testAdminCabinetInitDataRejected(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$rootData] = $this->registerTg(600, name: 'Root');
        // initData (cabinet) не проходит web.admin (нужен Sanctum Bearer).
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/1', $this->tgHeaders($rootData))
            ->assertStatus(401);
    }

    public function testAdminFlagOffBlocksEvenOwner(): void
    {
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/1', $this->adminHeaders($rootData))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testAdminNonPrivilegedRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$rootData] = $this->registerTg(600, name: 'Root');
        [$xData] = $this->registerTg(601, name: 'X');
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/1', $this->adminHeaders($xData))
            ->assertStatus(403);
    }

    public function testFinanceReadsByPeriodAndBreakdown(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$rootData] = $this->registerTg(600, name: 'Root');
        [$fData] = $this->registerTg(602, name: 'Fin');
        $this->grantRole(602, 'finance');
        $root = $this->memberByTg(600);
        $this->seedBonus($root->id, 7, 450);

        $headers = $this->adminHeaders($fData);
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/7', $headers)
            ->assertOk()->assertJsonPath('data.0.net_cents', 450)
            ->assertJsonPath('data.0.member_id', $root->id);

        $this->getJson("/api/v1/admin/v2/structure-bonuses/period/7/member/{$root->id}", $headers)
            ->assertOk()->assertJsonPath('data.explanation.rate_bps', 500);
    }

    public function testOwnerReadsBreakdown(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');
        $root = $this->memberByTg(600);
        $this->seedBonus($root->id, 7, 450);

        $this->getJson("/api/v1/admin/v2/structure-bonuses/period/7/member/{$root->id}", $this->adminHeaders($rootData))
            ->assertOk()->assertJsonPath('data.net_cents', 450);
    }

    public function testAdminBreakdownMissingReturns404(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');
        $this->getJson('/api/v1/admin/v2/structure-bonuses/period/7/member/999999', $this->adminHeaders($rootData))
            ->assertStatus(404);
    }
}
