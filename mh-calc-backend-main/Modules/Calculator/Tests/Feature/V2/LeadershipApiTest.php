<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Models\LeadershipBonusLine;
use Tests\TestCase;

/**
 * T08 [ПРАВА, negative-cases обязательны]: cabinet/admin роуты лидерского бонуса.
 * Deny-by-default: без auth 401; cabinet initData на admin 401; флаг OFF => 403
 * даже owner; admin read — owner-only (money-данные + аудит блокировок), finance/прочие 403;
 * cabinet отдаёт ТОЛЬКО СВОИ начисления (IDOR: receiver из auth, чужой id недоступен).
 */
class LeadershipApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function seedLine(int $receiverId, int $sourceMemberId, int $sourceSbId, int $amount, string $status): int
    {
        return (int) DB::table('v2_leadership_bonus_lines')->insertGetId([
            'period_id' => 1,
            'receiver_member_id' => $receiverId,
            'source_member_id' => $sourceMemberId,
            'source_structure_bonus_id' => $sourceSbId,
            'depth' => 1,
            'receiver_rank_key' => 'DIRECTOR',
            'receiver_tier' => 'ELITE',
            'rate_bp' => 2000,
            'base_cents' => $amount * 5,
            'amount_cents' => $amount,
            'status' => $status,
            'exclusion_reason' => null,
            'blocking_member_id' => null,
            'policy_version_id' => 1,
            'ledger_tx_id' => "v2:leadership:{$sourceSbId}:{$receiverId}",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ Cabinet

    public function test_cabinet_requires_auth(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        $this->getJson('/api/v1/cabinet/v2/leadership', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function test_cabinet_flag_off_blocks(): void
    {
        [$data] = $this->registerTg(600, name: 'A');
        $this->getJson('/api/v1/cabinet/v2/leadership', $this->tgHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_cabinet_returns_own_lines_only(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        [$aData] = $this->registerTg(600, name: 'A');
        [$bData] = $this->registerTg(601, name: 'B');
        $this->registerTg(700, name: 'SRC');
        $aId = $this->memberByTg(600)->id;
        $bId = $this->memberByTg(601)->id;
        $srcId = $this->memberByTg(700)->id;
        $this->seedLine($aId, $srcId, 1, 200000, LeadershipBonusLine::STATUS_POSTED);
        $this->seedLine($bId, $srcId, 2, 999999, LeadershipBonusLine::STATUS_POSTED);

        $resp = $this->getJson('/api/v1/cabinet/v2/leadership', $this->tgHeaders($aData))->assertOk();
        $resp->assertJsonCount(1, 'data');            // видит только свою строку (IDOR)
        $resp->assertJsonPath('data.0.amount_cents', 200000); // строка B (999999) не видна
    }

    public function test_cabinet_hides_exclusions(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        [$aData] = $this->registerTg(600, name: 'A');
        $this->registerTg(700, name: 'SRC');
        $aId = $this->memberByTg(600)->id;
        $srcId = $this->memberByTg(700)->id;
        $this->seedLine($aId, $srcId, 1, 0, LeadershipBonusLine::STATUS_EXCLUDED);

        $this->getJson('/api/v1/cabinet/v2/leadership', $this->tgHeaders($aData))
            ->assertOk()->assertJsonCount(0, 'data'); // excluded не показывается партнёру
    }

    // ------------------------------------------------------------------ Admin

    public function test_admin_requires_auth(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        $this->getJson('/api/v1/admin/v2/leadership', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function test_admin_cabinet_initdata_rejected(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        [$data] = $this->registerTg(600, name: 'A');
        $this->getJson('/api/v1/admin/v2/leadership', $this->tgHeaders($data))
            ->assertStatus(401);
    }

    public function test_admin_flag_off_blocks_even_owner(): void
    {
        [$data] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $this->getJson('/api/v1/admin/v2/leadership', $this->adminHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function test_admin_non_privileged_role_gets_403(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        [$xData] = $this->registerTg(601, name: 'X');
        $this->getJson('/api/v1/admin/v2/leadership', $this->adminHeaders($xData))
            ->assertStatus(403);
    }

    public function test_admin_finance_role_gets_403_owner_only(): void
    {
        // Admin read лидерского — owner-only (money + аудит блокировок); finance не проходит.
        $this->enableFeatureFlags('mh_v2_leadership');
        [$fData] = $this->registerTg(602, name: 'Fin');
        $this->grantRole(602, 'finance');
        $this->getJson('/api/v1/admin/v2/leadership', $this->adminHeaders($fData))
            ->assertStatus(403);
    }

    public function test_admin_owner_reads_lines_including_exclusions(): void
    {
        $this->enableFeatureFlags('mh_v2_leadership');
        [$oData] = $this->registerTg(600, name: 'Owner');
        $this->grantRole(600, 'owner');
        $this->registerTg(701, name: 'SRC');
        $ownerId = $this->memberByTg(600)->id;
        $srcId = $this->memberByTg(701)->id;
        $this->seedLine($ownerId, $srcId, 1, 200000, LeadershipBonusLine::STATUS_POSTED);
        // Строка-исключение видна админу (кормит T13).
        DB::table('v2_leadership_bonus_lines')->insert([
            'period_id' => 1, 'receiver_member_id' => $ownerId, 'source_member_id' => $srcId,
            'source_structure_bonus_id' => 9, 'depth' => 1, 'rate_bp' => 0, 'base_cents' => 1000,
            'amount_cents' => 0, 'status' => LeadershipBonusLine::STATUS_EXCLUDED,
            'exclusion_reason' => 'RANK_GAP_BLOCK', 'blocking_member_id' => $srcId, 'policy_version_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->getJson('/api/v1/admin/v2/leadership', $this->adminHeaders($oData))->assertOk();
        $resp->assertJsonCount(2, 'data'); // и начисление, и исключение
    }
}
