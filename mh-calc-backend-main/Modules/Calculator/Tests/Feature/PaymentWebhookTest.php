<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Приём оплаты через шлюз (Фаза 4, S3 / US-3): инвойс → webhook → заказ paid / депозит.
 * Гоняем FakeGateway (config payment_gateway=fake): подпись webhook = HMAC тела по секрету.
 * Проверяем идемпотентность, отбраковку плохой подписи и несоответствия суммы.
 */
class PaymentWebhookTest extends TestCase
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

    private function makeProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Silver', 'price_usdt_cents' => 18000, 'pv' => 180,
            'package_id' => 2, 'sku' => 'TARIFF-SILVER', 'is_active' => true, 'sort' => 2,
        ]);
    }

    /** Отправить подписанный webhook с произвольным телом. */
    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function payOrder(string $data, int $productId): array
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        return ['order_id' => $orderId, 'payment_id' => $pay['payment_id'], 'amount' => $pay['amount_cents']];
    }

    public function testInvoiceCreatedForOrder(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(500, name: 'Buyer');

        $ctx = $this->payOrder($data, $p->id);
        $this->assertSame(18000, $ctx['amount']);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($ctx['payment_id'])->status);
    }

    public function testWebhookMarksOrderPaid(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(510, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        $this->postWebhook([
            'external_ref' => "pay:{$ctx['payment_id']}",
            'provider_id' => 'fake_x',
            'status' => 'paid',
            'amount_cents' => $ctx['amount'],
        ])->assertOk();

        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
        $this->assertSame(Payment::STATUS_PAID, Payment::find($ctx['payment_id'])->status);
    }

    public function testWebhookIsIdempotent(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(520, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        $body = [
            'external_ref' => "pay:{$ctx['payment_id']}",
            'provider_id' => 'fake_x', 'status' => 'paid', 'amount_cents' => $ctx['amount'],
        ];
        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk()->assertJsonPath('idempotent', true);

        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
        $this->assertSame(1, Payment::where('status', Payment::STATUS_PAID)->count());
    }

    public function testWebhookRejectsBadSignature(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(530, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => 'wrong', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['external_ref' => "pay:{$ctx['payment_id']}", 'status' => 'paid', 'amount_cents' => $ctx['amount']]))
            ->assertStatus(400);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($ctx['order_id'])->status);
    }

    public function testWebhookRejectsAmountMismatch(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(540, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        $this->postWebhook([
            'external_ref' => "pay:{$ctx['payment_id']}",
            'status' => 'paid', 'amount_cents' => 100, // подмена суммы
        ])->assertStatus(400);

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($ctx['order_id'])->status);
    }

    public function testTopupCreditsBalanceOnWebhook(): void
    {
        [$data] = $this->registerTg(550, name: 'Buyer');

        $pay = $this->postJson('/api/v1/cabinet/wallet/topup',
            ['amount_cents' => 5000], $this->tgHeaders($data))->assertOk()->json('data');

        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}",
            'status' => 'paid', 'amount_cents' => 5000,
        ])->assertOk();

        $memberId = $this->memberByTg(550)->id;
        $this->assertSame(5000, MemberWallet::where('member_id', $memberId)->first()->available_cents);
    }
}
