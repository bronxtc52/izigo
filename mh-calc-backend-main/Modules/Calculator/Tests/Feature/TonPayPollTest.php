<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Приём TON Pay (Фаза 4, S3-TON / US-3): non-custodial подтверждение опросом сети.
 * Гоняем FakeTonPayGateway (config payment_gateway=ton_pay_fake): «приход» tx регистрируется
 * статически. Проверяем confirm по memo+сумме, отказ при неверной сумме, идемпотентность.
 */
class TonPayPollTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config(['calculator.payment_gateway' => 'ton_pay_fake']);
        FakeTonPayGateway::reset();
    }

    protected function tearDown(): void
    {
        FakeTonPayGateway::reset();
        parent::tearDown();
    }

    private function makeProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Silver', 'price_usdt_cents' => 18000, 'pv' => 180,
            'package_id' => 2, 'sku' => 'TARIFF-SILVER', 'is_active' => true, 'sort' => 2,
        ]);
    }

    private function payOrder(string $data, int $productId): array
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        return ['order_id' => $orderId, 'payment_id' => $pay['payment_id'], 'memo' => $pay['memo'], 'amount' => $pay['amount_cents']];
    }

    public function testPollConfirmsOrderWhenTxArrived(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(1000, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        // Деньги «пришли» on-chain с верным memo и суммой.
        FakeTonPayGateway::fakePay($ctx['memo'], $ctx['amount']);
        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
        $this->assertSame(Payment::STATUS_PAID, Payment::find($ctx['payment_id'])->status);
    }

    public function testPollLeavesPendingWhenNoTx(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(1010, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0); // tx не пришла

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($ctx['order_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($ctx['payment_id'])->status);
    }

    public function testWrongAmountMarksFailedNotPaid(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(1020, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        FakeTonPayGateway::fakePay($ctx['memo'], 100); // подмена суммы
        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_FAILED, Payment::find($ctx['payment_id'])->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($ctx['order_id'])->status);
    }

    public function testImmediateCheckEndpointConfirms(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(1030, name: 'Buyer');
        $ctx = $this->payOrder($data, $p->id);

        FakeTonPayGateway::fakePay($ctx['memo'], $ctx['amount']);
        $this->postJson("/api/v1/cabinet/payments/{$ctx['payment_id']}/check", [], $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.payment_status', Payment::STATUS_PAID);

        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
    }

    public function testTopupConfirmedViaPoll(): void
    {
        [$data] = $this->registerTg(1040, name: 'Buyer');
        $pay = $this->postJson('/api/v1/cabinet/wallet/topup',
            ['amount_cents' => 5000], $this->tgHeaders($data))->assertOk()->json('data');

        FakeTonPayGateway::fakePay($pay['memo'], 5000);
        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        $memberId = $this->memberByTg(1040)->id;
        $this->assertSame(5000, MemberWallet::where('member_id', $memberId)->first()->available_cents);
    }

    public function testPollIsIdempotent(): void
    {
        $p = $this->makeProduct();
        [$rootData, $rootRef] = $this->registerTg(1050, name: 'Root');
        [$aData] = $this->registerTg(1051, $rootRef, 'A');
        // Root активен (через TON Pay), чтобы реферал начислялся.
        $rootCtx = $this->payOrder($rootData, $p->id);
        FakeTonPayGateway::fakePay($rootCtx['memo'], $rootCtx['amount']);
        $this->artisan('commerce:tonpay-poll');

        $aCtx = $this->payOrder($aData, $p->id);
        FakeTonPayGateway::fakePay($aCtx['memo'], $aCtx['amount']);
        $this->artisan('commerce:tonpay-poll');
        $this->artisan('commerce:tonpay-poll'); // повтор не должен задваивать

        // Silver = package 2 = 180 PV; реферал 10% = $18 ровно один раз.
        $balance = $this->getJson('/api/v1/cabinet/wallet', $this->tgHeaders($rootData))->json('data.available');
        $this->assertSame('18.00', $balance);
    }
}
