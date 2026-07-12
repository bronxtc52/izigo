<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
use Modules\Calculator\V2\Services\Volume\PvLotIngestService;
use Tests\TestCase;

/**
 * T03 [ДЕНЬГИ]: ручной reversal (refund) volume-слоя.
 * Несматченный остаток лота реверсится (pv_reversed, state), проекция веток
 * пересчитывается; сматченные лоты НЕ удаляются — история неизменна, связанные
 * матчи помечаются reversal_required (каскад денег — T12). Снапшот заказа
 * immutable всегда.
 */
class VolumeReversalTest extends TestCase
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
        $this->enableFeatureFlags('mh_v2_volumes');
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

    private function ingest(): PvLotIngestService
    {
        return app(PvLotIngestService::class);
    }

    public function testReverseUnmatchedLotFully(): void
    {
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        $orderId = $this->buyAndPay($aData, $bronze->id);
        $rootId = $this->memberByTg(600)->id;

        $result = $this->ingest()->reverseUnmatchedLotsForOrder($orderId, 'ручной возврат');

        $lot = PvLot::query()->where('origin_order_id', $orderId)->sole();
        $this->assertSame(PvLot::STATE_REVERSED, $lot->state);
        $this->assertSame(0, bccomp($lot->pv_available, '0', 6));
        $this->assertSame(0, bccomp($lot->pv_reversed, '90', 6));
        $this->assertSame(0, bccomp($lot->pv_matched, '0', 6));
        $this->assertSame([$lot->id], $result['reversed_lot_ids']);
        $this->assertSame([], $result['affected_match_ids']); // матчей не было

        // Проекция пересчитана: реверс выпал и из free, и из lifetime.
        $stats = DB::table('v2_member_branch_stats')->where('member_id', $rootId)->first();
        $this->assertSame(0, bccomp($stats->left_free_pv, '0', 6));
        $this->assertSame(0, bccomp($stats->left_lifetime_pv, '0', 6));
        $this->assertNull($stats->large_side);

        // Снапшот заказа — immutable, refund его не трогает (DEC-003).
        $this->assertSame(1, OrderVolumeSnapshot::query()->where('order_id', $orderId)->count());
    }

    public function testReverseAfterMatchingKeepsHistoryAndFlagsMatch(): void
    {
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $orderA = $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $rootId = $this->memberByTg(600)->id;

        $match = app(BinaryMatchingService::class)
            ->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');
        $this->assertSame(0, bccomp($match->matched_pv, '90', 6));

        $result = $this->ingest()->reverseUnmatchedLotsForOrder($orderA, 'возврат после матчинга');

        // Лот полностью сматчен: реверсить нечего, лот НЕ удалён, история цела.
        $lot = PvLot::query()->where('origin_order_id', $orderA)->sole();
        $this->assertSame(PvLot::STATE_EXHAUSTED, $lot->state);
        $this->assertSame(0, bccomp($lot->pv_matched, '90', 6));
        $this->assertSame(0, bccomp($lot->pv_reversed, '0', 6));
        $this->assertSame([], $result['reversed_lot_ids']);

        // Матч помечен на каскадный reversal (T12), но статус/суммы не переписаны.
        $this->assertSame([$match->id], $result['affected_match_ids']);
        $fresh = BinaryMatch::query()->find($match->id);
        $this->assertNotNull($fresh->reversal_required_at);
        $this->assertSame('возврат после матчинга', $fresh->reversal_reason);
        $this->assertSame(BinaryMatch::STATUS_PROVISIONAL, $fresh->status);
        $this->assertSame(9000, $fresh->matched_bv_usd_cents);
        $this->assertSame(2, $fresh->allocations()->count()); // аллокации не удалены
    }

    public function testReversePartiallyMatchedLotReversesOnlyRemainder(): void
    {
        // 90L (A) vs 30R: matched 30, у лота A остаток 60 — реверс забирает ТОЛЬКО его.
        $bronze = $this->bronze();
        $small = Product::query()->create([
            'name' => 'Trial', 'price_usdt_cents' => 3000, 'pv' => 30,
            'package_id' => 1, 'sku' => 'TARIFF-TRIAL', 'is_active' => true, 'sort' => 2,
        ]);
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $orderA = $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $small->id);
        $rootId = $this->memberByTg(600)->id;

        $match = app(BinaryMatchingService::class)
            ->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');
        $this->assertSame(0, bccomp($match->matched_pv, '30', 6));

        $result = $this->ingest()->reverseUnmatchedLotsForOrder($orderA, 'частичный кейс');

        $lot = PvLot::query()->where('origin_order_id', $orderA)->sole();
        // Часть сматчена — состояние exhausted (не reversed): историю не переписываем.
        $this->assertSame(PvLot::STATE_EXHAUSTED, $lot->state);
        $this->assertSame(0, bccomp($lot->pv_matched, '30', 6));
        $this->assertSame(0, bccomp($lot->pv_reversed, '60', 6));
        $this->assertSame(0, bccomp($lot->pv_available, '0', 6));
        // Инвариант: 0 + 30 + 60 = 90.
        $sum = bcadd(bcadd($lot->pv_available, $lot->pv_matched, 6), $lot->pv_reversed, 6);
        $this->assertSame(0, bccomp($sum, $lot->pv_original, 6));

        // Матч, потребивший лот, помечен на каскад.
        $this->assertSame([$match->id], $result['affected_match_ids']);

        // Проекция: lifetime слева = 90-60=30, справа 30 — tie.
        $stats = DB::table('v2_member_branch_stats')->where('member_id', $rootId)->first();
        $this->assertSame(0, bccomp($stats->left_lifetime_pv, '30', 6));
        $this->assertNull($stats->large_side);
    }

    public function testReverseIsIdempotent(): void
    {
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        $orderId = $this->buyAndPay($aData, $bronze->id);

        $first = $this->ingest()->reverseUnmatchedLotsForOrder($orderId, 'r1');
        $second = $this->ingest()->reverseUnmatchedLotsForOrder($orderId, 'r2');

        $this->assertCount(1, $first['reversed_lot_ids']);
        $this->assertSame([], $second['reversed_lot_ids']); // повтор — no-op
        $lot = PvLot::query()->where('origin_order_id', $orderId)->sole();
        $this->assertSame(0, bccomp($lot->pv_reversed, '90', 6)); // не задвоилось
    }
}
