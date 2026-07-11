<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Contracts\PvLotService;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Models\PvLot;
use Tests\TestCase;

/**
 * T03 [ДЕНЬГИ]: инжест volume-слоя V2 на боевом пути оплаты (webhook → markPaid →
 * PaidOrderV2Pipeline). Лоты у ВСЕХ бинарных предков с корректной стороной, включая
 * spillover-потомка не из реферального дерева (DEC-055); снапшот immutable (DEC-003);
 * идемпотентность (AT-IDEM-001); негатив: флаг OFF => ни одной V2-строки (V1 не тронут).
 */
class PvLotIngestTest extends TestCase
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

    /** Дерево: root; A=left(root), B=right(root), C=spillover под A left (спонсор C — root). */
    private function tree(): array
    {
        [$rootData, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        [$cData] = $this->registerTg(603, $rootRef, 'C');

        return [$rootData, $rootRef, $aData, $bData, $cData];
    }

    public function testPaidOrderCreatesLotsForAllBinaryAncestorsIncludingSpillover(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [, $rootRef, , , $cData] = $this->tree();

        $root = $this->memberByTg(600);
        $a = $this->memberByTg(601);
        $c = $this->memberByTg(603);
        // Прекондиция спилловера: C в бинаре сидит под A, но спонсор C — root.
        $this->assertSame($a->id, $c->parent_id);
        $this->assertSame($root->id, $c->sponsor_id);

        $orderId = $this->buyAndPay($cData, $bronze->id);

        // Снапшот позиции заказа: PV/BV раздельно, decimal/центы.
        $snapshot = OrderVolumeSnapshot::query()->where('order_id', $orderId)->sole();
        $this->assertSame('90.000000', $snapshot->pv);
        $this->assertSame(9000, $snapshot->bv_usd_cents); // BV NULL => BV = цене
        $this->assertSame(1, $snapshot->policy_version_id); // заглушка до merge T01

        // Лоты у ОБОИХ бинарных предков (root и A — spillover включён), сторона left.
        $lots = PvLot::query()->orderBy('owner_member_id')->get();
        $this->assertCount(2, $lots);
        $this->assertEqualsCanonicalizing(
            [[$root->id, 'left'], [$a->id, 'left']],
            $lots->map(fn ($l) => [$l->owner_member_id, $l->side])->all()
        );
        foreach ($lots as $lot) {
            $this->assertSame($c->id, $lot->buyer_member_id);
            $this->assertSame('90.000000', $lot->pv_original);
            $this->assertSame('90.000000', $lot->pv_available);
            $this->assertSame(9000, $lot->bv_usd_cents_original);
            $this->assertSame(PvLot::STATE_FREE, $lot->state);
            $this->assertLotBalanced($lot);
        }

        // Branch-stats предков пересчитаны.
        $rootStats = DB::table('v2_member_branch_stats')->where('member_id', $root->id)->first();
        $this->assertNotNull($rootStats);
        $this->assertSame(0, bccomp($rootStats->left_free_pv, '90', 6));
        $this->assertSame(0, bccomp($rootStats->right_free_pv, '0', 6));
        $this->assertSame('left', $rootStats->large_side);
    }

    public function testRightSideLotAndBvOverride(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        // BV тарифа задан отдельно от цены — снапшот берёт bv_usd_cents.
        $bronze->update(['bv_usd_cents' => 5000]);
        [, , , $bData] = $this->tree();

        $root = $this->memberByTg(600);
        $b = $this->memberByTg(602);

        $orderId = $this->buyAndPay($bData, $bronze->id);

        $snapshot = OrderVolumeSnapshot::query()->where('order_id', $orderId)->sole();
        $this->assertSame(5000, $snapshot->bv_usd_cents);

        $lot = PvLot::query()->where('buyer_member_id', $b->id)->sole();
        $this->assertSame($root->id, $lot->owner_member_id);
        $this->assertSame('right', $lot->side);
        $this->assertSame(5000, $lot->bv_usd_cents_original);
    }

    public function testRootBuyerCreatesNoLots(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [$rootData] = $this->tree();

        $orderId = $this->buyAndPay($rootData, $bronze->id);

        // Снапшот есть (personal PV для T05), лотов нет — у корня нет бинарных предков.
        $this->assertSame(1, OrderVolumeSnapshot::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, PvLot::query()->count());
    }

    public function testSnapshotImmutableAfterProductChange(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [, , $aData] = $this->tree();

        $orderId = $this->buyAndPay($aData, $bronze->id);

        // Смена цены/PV/BV товара ПОСЛЕ оплаты не трогает снапшот и лоты (DEC-003).
        $bronze->update(['price_usdt_cents' => 123456, 'pv' => 7, 'bv_usd_cents' => 42]);

        $snapshot = OrderVolumeSnapshot::query()->where('order_id', $orderId)->sole();
        $this->assertSame('90.000000', $snapshot->pv);
        $this->assertSame(9000, $snapshot->bv_usd_cents);
        $lot = PvLot::query()->where('origin_order_id', $orderId)->sole();
        $this->assertSame('90.000000', $lot->pv_original);
        $this->assertSame(9000, $lot->bv_usd_cents_original);
    }

    public function testIngestIdempotentOnRepeatedCalls(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes');
        $bronze = $this->bronze();
        [, , $aData] = $this->tree();

        $orderId = $this->buyAndPay($aData, $bronze->id);
        $lotsBefore = PvLot::query()->count();
        $snapshotsBefore = OrderVolumeSnapshot::query()->count();

        // Повторный прогон V2-инжеста по тому же заказу (ретрай вебхука/крон-добор):
        // unique-ключи снапшотов и лотов гасят дубли (AT-IDEM-001).
        DB::transaction(function () use ($orderId) {
            app(ActivationService::class)->acquireActivationLock();
            app(PvLotService::class)->recordPaidOrder($orderId);
        });

        $this->assertSame($lotsBefore, PvLot::query()->count());
        $this->assertSame($snapshotsBefore, OrderVolumeSnapshot::query()->count());
    }

    public function testActivationLockGuardRejectsSessionWithoutLock(): void
    {
        // Дисциплина локов (amendments #5). Под RefreshDatabase advisory-xact-lock,
        // взятый оплатой, живёт до конца теста (общая обёрточная транзакция), поэтому
        // «сессию без лока» моделируем ВТОРЫМ подключением к той же БД.
        config(['database.connections.pgsql_guard_probe' => config('database.connections.pgsql')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/advisory-lock/');
        (new \Modules\Calculator\V2\Services\Volume\ActivationLockGuard('pgsql_guard_probe'))->assertLockHeld();
    }

    public function testActivationLockGuardPassesWhenLockHeld(): void
    {
        DB::transaction(function () {
            app(ActivationService::class)->acquireActivationLock();
            (new \Modules\Calculator\V2\Services\Volume\ActivationLockGuard())->assertLockHeld();
            $this->assertTrue(true); // не бросил — лок виден из своей сессии
        });
    }

    public function testFlagOffWritesNoV2RowsAndV1Unchanged(): void
    {
        // Флаг mh_v2_volumes НЕ включён (deny-by-default).
        $bronze = $this->bronze();
        [$rootData, , $aData] = $this->tree();
        $this->buyAndPay($rootData, $bronze->id); // спонсор активен для реферала V1

        $orderId = $this->buyAndPay($aData, $bronze->id);

        // V1-путь работает как раньше: заказ оплачен и активирован.
        $order = Order::query()->find($orderId);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->activation_event_id);
        $this->assertSame('active', Member::query()->find($this->memberByTg(601)->id)->status);

        // Ни одной V2-строки.
        $this->assertSame(0, DB::table('v2_order_volume_snapshots')->count());
        $this->assertSame(0, DB::table('v2_pv_lots')->count());
        $this->assertSame(0, DB::table('v2_binary_matches')->count());
        $this->assertSame(0, DB::table('v2_member_branch_stats')->count());
    }

    /** Инвариант лота: available + matched + reversed = original (CHECK в БД + пояс). */
    private function assertLotBalanced(PvLot $lot): void
    {
        $sum = bcadd(bcadd($lot->pv_available, $lot->pv_matched, 6), $lot->pv_reversed, 6);
        $this->assertSame(0, bccomp($sum, $lot->pv_original, 6));
    }
}
