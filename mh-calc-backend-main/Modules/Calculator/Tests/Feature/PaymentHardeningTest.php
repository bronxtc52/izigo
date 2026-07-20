<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * P1-hardening поллера платежей (B1 + B4):
 *  - poison-платёж не останавливает конвейер подтверждений и TTL-блок;
 *  - сбой опроса ('error') не даёт TTL съесть потенциально оплаченный платёж;
 *  - админ-ручка recheck возвращает деньги expired-платежу.
 */
class PaymentHardeningTest extends TestCase
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

    // --- G5: один живой pending-инвойс на заказ ---

    public function testRepeatedPayReusesSamePendingInvoice(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2100, name: 'A');
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $p->id], $this->tgHeaders($data))->json('data.id');

        $pay1 = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');
        $pay2 = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        // Повторный клик вернул ТОТ ЖЕ инвойс (payment_id/memo), а не новый memo.
        $this->assertSame($pay1['payment_id'], $pay2['payment_id']);
        $this->assertSame($pay1['memo'], $pay2['memo']);
        // На заказ — ровно один живой платёж.
        $this->assertSame(1, Payment::where('order_id', $orderId)
            ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])->count());
    }

    public function testPartialUniqueIndexRejectsSecondLivePending(): void
    {
        // Защита на уровне БД: прямой insert второго живого pending на тот же заказ отбивается
        // партиал-unique индексом (страховка от гонки двух кликов мимо reuse-ветки).
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2110, name: 'A');
        $ctx = $this->payOrder($data, $p->id);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        Payment::query()->create([
            'order_id' => $ctx['order_id'],
            'member_id' => $this->memberByTg(2110)->id,
            'provider' => 'ton_pay',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => $ctx['amount'],
            'currency' => 'USDT',
            'status' => Payment::STATUS_PENDING,
            'external_ref' => 'pay:manual-dup',
        ]);
    }

    public function testExpiredInvoiceLetsOrderBePaidAgain(): void
    {
        // Терминальный статус выходит из партиал-индекса: после экспирации инвойса заказ
        // можно оплатить заново — reuse не подхватывает expired, создаётся свежий инвойс.
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2120, name: 'A');
        $ctx = $this->payOrder($data, $p->id);

        Payment::where('id', $ctx['payment_id'])->update(['status' => Payment::STATUS_EXPIRED]);

        $orderId = $ctx['order_id'];
        $pay2 = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        $this->assertNotSame($ctx['payment_id'], $pay2['payment_id']);
        $this->assertSame(Payment::STATUS_EXPIRED, Payment::find($ctx['payment_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($pay2['payment_id'])->status);
    }

    // --- B1: poison-платёж ---

    public function testPoisonPaymentDoesNotBlockOthers(): void
    {
        $p = $this->makeProduct();
        [$aData] = $this->registerTg(2000, name: 'A');
        [$bData] = $this->registerTg(2001, name: 'B');
        $poison = $this->payOrder($aData, $p->id);
        $healthy = $this->payOrder($bData, $p->id);

        // Ломаем первый платёж: order-платёж без order_id → applyPaid бросает RuntimeException.
        Payment::where('id', $poison['payment_id'])->update(['order_id' => null]);
        FakeTonPayGateway::fakePay($poison['memo'], $poison['amount']);
        FakeTonPayGateway::fakePay($healthy['memo'], $healthy['amount']);

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        // Сосед подтверждён, конвейер не упал; poison остался pending (для разбора).
        $this->assertSame(Payment::STATUS_PAID, Payment::find($healthy['payment_id'])->status);
        $this->assertSame(Order::STATUS_PAID, Order::find($healthy['order_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($poison['payment_id'])->status);
    }

    public function testPoisonPaymentDoesNotBlockTtlBlock(): void
    {
        $p = $this->makeProduct();
        [$aData] = $this->registerTg(2010, name: 'A');
        [$bData] = $this->registerTg(2011, name: 'B');
        $poison = $this->payOrder($aData, $p->id);
        $stale = $this->payOrder($bData, $p->id);

        Payment::where('id', $poison['payment_id'])->update(['order_id' => null]);
        FakeTonPayGateway::fakePay($poison['memo'], $poison['amount']);

        config(['calculator.payment_pending_ttl_minutes' => 30]);
        Payment::where('id', $stale['payment_id'])->update(['created_at' => now()->subHour()]);

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        // TTL-блок выполнился, несмотря на исключение по poison-платежу.
        $this->assertSame(Payment::STATUS_EXPIRED, Payment::find($stale['payment_id'])->status);
    }

    // --- B4: сбой опроса не ведёт к экспирации ---

    public function testPollErrorPreventsExpiration(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2020, name: 'A');
        $ctx = $this->payOrder($data, $p->id);

        config(['calculator.payment_pending_ttl_minutes' => 30]);
        Payment::where('id', $ctx['payment_id'])->update(['created_at' => now()->subHour()]);
        FakeTonPayGateway::failFor($ctx['memo']); // опрос по этому memo «падает»

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        // Деньги могли прийти, а проверить не смогли → платёж живёт до успешного опроса.
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($ctx['payment_id'])->status);
    }

    public function testIndexerOutageSkipsTtlEntirely(): void
    {
        $p = $this->makeProduct();
        [$aData] = $this->registerTg(2030, name: 'A');
        [$bData] = $this->registerTg(2031, name: 'B');
        $one = $this->payOrder($aData, $p->id);
        $two = $this->payOrder($bData, $p->id);

        config(['calculator.payment_pending_ttl_minutes' => 30]);
        Payment::whereIn('id', [$one['payment_id'], $two['payment_id']])
            ->update(['created_at' => now()->subHour()]);
        FakeTonPayGateway::$failAll = true; // индексатор лежит целиком

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_PENDING, Payment::find($one['payment_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($two['payment_id'])->status);
    }

    public function testSuccessfulPollStillExpiresStale(): void
    {
        // Контроль против ложной защиты: успешно опрошенный stale-платёж экспирируется как раньше.
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2040, name: 'A');
        $ctx = $this->payOrder($data, $p->id);

        config(['calculator.payment_pending_ttl_minutes' => 30]);
        Payment::where('id', $ctx['payment_id'])->update(['created_at' => now()->subHour()]);

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);

        $this->assertSame(Payment::STATUS_EXPIRED, Payment::find($ctx['payment_id'])->status);
    }

    public function testCheckEndpointKeepsPendingOnPollError(): void
    {
        $p = $this->makeProduct();
        [$data] = $this->registerTg(2050, name: 'A');
        $ctx = $this->payOrder($data, $p->id);

        FakeTonPayGateway::failFor($ctx['memo']);

        // Для пользователя сбой опроса неотличим от «ещё не пришло».
        $this->postJson("/api/v1/cabinet/payments/{$ctx['payment_id']}/check", [], $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.payment_status', Payment::STATUS_PENDING);
    }

    // --- B4: админ-ручка recheck ---

    public function testAdminRecheckConfirmsExpiredPayment(): void
    {
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(2060, name: 'Owner');
        $this->grantRole(2060, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);

        // Платёж съеден TTL (деньги в этот момент проверить не смогли), потом деньги нашлись.
        Payment::where('id', $ctx['payment_id'])->update(['status' => Payment::STATUS_EXPIRED]);
        FakeTonPayGateway::fakePay($ctx['memo'], $ctx['amount']);

        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_PAID)
            ->assertJsonPath('data.poll', 'paid');

        // Fulfillment прошёл: заказ оплачен, участник активирован.
        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
        $this->assertSame('active', $this->memberByTg(2060)->fresh()->status);
    }

    public function testAdminRecheckIsIdempotentWithPoller(): void
    {
        // Гонка recheck ↔ тик поллера: оба пути идут через confirmPayment (лок) и
        // activate (advisory-lock + idempotency-key) — активация ровно одна.
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(2070, name: 'Owner');
        $this->grantRole(2070, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);

        FakeTonPayGateway::fakePay($ctx['memo'], $ctx['amount']);
        $this->artisan('commerce:tonpay-poll')->assertExitCode(0); // поллер успел первым

        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_PAID)
            ->assertJsonPath('data.poll', 'skipped');

        $this->assertSame(1, \Modules\Calculator\Models\ActivationEvent::query()
            ->where('member_id', $this->memberByTg(2070)->id)->count());
    }

    public function testAdminRecheckErrorKeepsExpired(): void
    {
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(2080, name: 'Owner');
        $this->grantRole(2080, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);

        Payment::where('id', $ctx['payment_id'])->update(['status' => Payment::STATUS_EXPIRED]);
        FakeTonPayGateway::failFor($ctx['memo']);

        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_EXPIRED)
            ->assertJsonPath('data.poll', 'error');

        // t2 (аддитивно): сбойный recheck зафиксирован в колонках наблюдаемости.
        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame('error', $payment->last_poll_result);
        $this->assertSame(1, $payment->poll_error_streak);
        $this->assertNotNull($payment->last_polled_at);
    }

    public function testAdminRecheckDeniedForSupport(): void
    {
        $p = $this->makeProduct();
        [$ownerData, $ownerRef] = $this->registerTg(2090, name: 'Owner');
        $this->grantRole(2090, 'owner');
        [$supportData] = $this->registerTg(2091, $ownerRef, 'Support');
        $this->grantRole(2091, 'support');
        $ctx = $this->payOrder($ownerData, $p->id);

        // Финансовая ручка: owner/finance; support — 403.
        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($supportData))
            ->assertForbidden();
    }
}
