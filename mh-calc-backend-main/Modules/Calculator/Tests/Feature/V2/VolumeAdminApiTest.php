<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Models\BinaryMatch;
use Tests\TestCase;

/**
 * T03 [ПРАВА, negative-cases]: admin-эндпоинты volume-слоя.
 * Deny-by-default: без auth 401; cabinet-токен (initData) на admin-роуте 401;
 * флаг OFF => 403 FEATURE_DISABLED даже владельцу; не-owner/finance роль 403;
 * finance читает, но НЕ запускает матчинг (owner-only mutation) —
 * amendments nice-to-have #1.
 */
class VolumeAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function buyAndPay(string $data, int $productId): int
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();

        return $orderId;
    }

    /** root(owner) + A(left) + B(right, роль finance) + покупки с обеих сторон. */
    private function seedVolumes(): array
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [$rootData, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $orderA = $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $this->grantRole(600, 'owner');
        $this->grantRole(602, 'finance');

        return [$rootData, $aData, $bData, $orderA];
    }

    public function testUnauthenticatedGets401(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $this->getJson('/api/v1/admin/v2/volumes/pv-lots', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
        $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => 1, 'period_key' => '2026-07-H1'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetInitDataHeadersRejectedOnAdminRoutes(): void
    {
        [, $aData] = $this->seedVolumes();

        // Cabinet-аутентификация (initData) не проходит web.admin (нужен Sanctum Bearer).
        $this->getJson('/api/v1/admin/v2/volumes/pv-lots', $this->tgHeaders($aData))
            ->assertStatus(401);
    }

    public function testFeatureFlagOffBlocksEvenOwner(): void
    {
        $bronze = $this->bronze();
        [$rootData] = $this->registerTg(600, name: 'Root');
        $this->grantRole(600, 'owner');

        // Флаг mh_v2_volumes НЕ включён: 403 FEATURE_DISABLED даже владельцу.
        $this->getJson('/api/v1/admin/v2/volumes/pv-lots', $this->adminHeaders($rootData))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testMemberWithoutRoleGets403(): void
    {
        [, $aData] = $this->seedVolumes();

        $this->getJson('/api/v1/admin/v2/volumes/pv-lots', $this->adminHeaders($aData))
            ->assertStatus(403);
    }

    public function testFinanceCanReadButCannotRunMatching(): void
    {
        [, , $bData] = $this->seedVolumes();
        $rootId = $this->memberByTg(600)->id;
        $headers = $this->adminHeaders($bData);

        $this->getJson('/api/v1/admin/v2/volumes/pv-lots', $headers)->assertOk();
        $this->getJson('/api/v1/admin/v2/volumes/binary-matches', $headers)->assertOk();
        $this->getJson("/api/v1/admin/v2/volumes/branch-stats/{$rootId}", $headers)->assertOk();

        // Mutation — owner-only.
        $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => $rootId, 'period_key' => '2026-07-H1'], $headers)
            ->assertStatus(403);
        $this->assertSame(0, BinaryMatch::query()->count());
    }

    public function testOwnerReadsLotsMatchesStatsSnapshots(): void
    {
        [$rootData, , , $orderA] = $this->seedVolumes();
        $rootId = $this->memberByTg(600)->id;
        $headers = $this->adminHeaders($rootData);

        $lots = $this->getJson('/api/v1/admin/v2/volumes/pv-lots?member_id=' . $rootId, $headers)
            ->assertOk()->json('data');
        $this->assertCount(2, $lots); // 90L + 90R

        $this->getJson('/api/v1/admin/v2/volumes/pv-lots?side=left&state=free', $headers)
            ->assertOk()->assertJsonCount(1, 'data');

        $stats = $this->getJson("/api/v1/admin/v2/volumes/branch-stats/{$rootId}", $headers)
            ->assertOk()->json('data');
        $this->assertSame(0, bccomp((string) $stats['left_free_pv'], '90', 6));

        $snapshots = $this->getJson('/api/v1/admin/v2/volumes/order-volume-snapshots?order_id=' . $orderA, $headers)
            ->assertOk()->json('data');
        $this->assertCount(1, $snapshots);
        $this->assertSame(9000, $snapshots[0]['bv_usd_cents']);
    }

    public function testOwnerRunsMatchingManuallyAndRepeatIsIdempotent(): void
    {
        [$rootData] = $this->seedVolumes();
        $rootId = $this->memberByTg(600)->id;
        $headers = $this->adminHeaders($rootData);
        $payload = ['member_id' => $rootId, 'period_key' => '2026-07-H2'];

        $first = $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run', $payload, $headers)
            ->assertOk()->json('data');
        $this->assertSame(0, bccomp((string) $first['matched_pv'], '90', 6));
        $this->assertSame(9000, $first['matched_bv_usd_cents']);
        $this->assertSame('period:2026-07-H2', $first['run_uuid']);
        $this->assertCount(2, $first['allocations']);

        // Повтор того же прогона — тот же матч, дублей нет.
        $second = $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run', $payload, $headers)
            ->assertOk()->json('data');
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, BinaryMatch::query()->count());
        $this->assertSame(2, DB::table('v2_pv_lot_allocations')->count());
    }

    public function testRunMatchingValidatesPeriodKey(): void
    {
        [$rootData] = $this->seedVolumes();
        $rootId = $this->memberByTg(600)->id;

        $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => $rootId, 'period_key' => 'июль-2026'], $this->adminHeaders($rootData))
            ->assertStatus(422);
        $this->postJson('/api/v1/admin/v2/volumes/binary-matches/run',
            ['member_id' => 999999, 'period_key' => '2026-07-H1'], $this->adminHeaders($rootData))
            ->assertStatus(422);
    }
}
