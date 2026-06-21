<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * E2E (Фаза 4, S4 / US-4): оплаченный заказ тарифа активирует пакет через существующий
 * ActivationService → пересчёт сети → дельта в ledger → баланс спонсора растёт.
 * Bronze = package_id 1 = 90 PV; реферал спонсору 10% = $9.
 */
class OrderActivationTest extends TestCase
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

    public function testPaidOrderActivatesAndCreditsSponsor(): void
    {
        $bronze = $this->bronze();
        [$rootData, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');

        // Спонсор активен (покупает тариф сам) — иначе реферал не начисляется.
        $this->buyAndPay($rootData, $bronze->id);
        // Партнёр A покупает тариф — это должно начислить реферал Root.
        $orderId = $this->buyAndPay($aData, $bronze->id);

        // Заказ оплачен и привязан к событию активации; A активен.
        $order = Order::find($orderId);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->activation_event_id);
        $this->assertSame('active', Member::find($this->memberByTg(601)->id)->status);

        // Баланс Root вырос на реферал $9.
        $balance = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->json('data.available');
        $this->assertSame('9.00', $balance);
    }

    public function testRepeatedWebhookDoesNotDoubleAccrue(): void
    {
        $bronze = $this->bronze();
        [$rootData, $rootRef] = $this->registerTg(610, name: 'Root');
        [$aData] = $this->registerTg(611, $rootRef, 'A');
        $this->buyAndPay($rootData, $bronze->id);

        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $bronze->id], $this->tgHeaders($aData))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($aData))->json('data');
        $body = ['external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents']];

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk(); // повтор

        // Реферал Root по-прежнему ровно $9 (активация идемпотентна по order:{id}).
        $balance = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->json('data.available');
        $this->assertSame('9.00', $balance);
    }
}
