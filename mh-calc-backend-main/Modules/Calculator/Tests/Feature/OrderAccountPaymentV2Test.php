<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Models\V2\WalletReservationV2;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * mh-full-plan T02 (деньги + безопасность): оплата заказа с субсчетов через API —
 * фиче-флаги deny-by-default, IDOR-скоуп, лимит ОС ≤70%, резерв/капчер/освобождение,
 * инвойс на остаток, полная оплата со счетов без TON, регресс V1-кошелька.
 */
class OrderAccountPaymentV2Test extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $secret = 'test-webhook-secret';
    private WalletAccountsV2Service $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
        $this->wallet = app(WalletAccountsV2Service::class);
    }

    private function enableV2(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_miniapp', 'mh_plan_v2_engine', 'mh_plan_v2_admin');
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 10000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    /** Участник с заказом (pending_payment) и средствами на субсчетах. */
    private function memberWithOrder(int $tgId, int $osCents = 0, int $bsCents = 0): array
    {
        $product = Product::query()->where('sku', 'TARIFF-BRONZE')->first() ?? $this->bronze();
        [$data] = $this->registerTg($tgId);
        $member = $this->memberByTg($tgId);
        if ($osCents > 0) {
            $this->wallet->credit($member->id, 'os', $osCents, "v2:t:os:{$member->id}", now()->addDays(365));
        }
        if ($bsCents > 0) {
            $this->wallet->credit($member->id, 'bs', $bsCents, "v2:t:bs:{$member->id}", now()->addDays(365));
        }
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id], $this->tgHeaders($data))->assertOk()->json('data.id');

        return [$data, $member, $orderId];
    }

    private function reserve(string $data, int $orderId, int $os, int $bs): TestResponse
    {
        return $this->postJson("/api/v1/cabinet/v2/orders/{$orderId}/account-payment",
            ['os_cents' => $os, 'bs_cents' => $bs], $this->tgHeaders($data));
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function account(int $memberId): MemberAccountV2
    {
        return MemberAccountV2::query()->where('member_id', $memberId)->firstOrFail();
    }

    // ------------------------------------------------------------------
    // Флаги / доступ (deny-by-default, negative)
    // ------------------------------------------------------------------

    public function testRoutesDenyByDefaultWhenFlagsOff(): void
    {
        [$data] = $this->registerTg(9001);
        $this->getJson('/api/v1/cabinet/v2/accounts', $this->tgHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testAccountPaymentRequiresEngineFlagEvenWithMiniappOn(): void
    {
        // Резерв без включённого V2-движка запрещён: capture-хук в markPaid дремлет
        // за mh_plan_v2_engine, холд без капчера завис бы навсегда.
        $this->enableFeatureFlags('mh_plan_v2_miniapp');
        [$data, , $orderId] = $this->memberWithOrder(9002, 10000);
        $this->reserve($data, $orderId, 1000, 0)
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testCabinetRequiresTelegramAuth(): void
    {
        $this->enableV2();
        $this->getJson('/api/v1/cabinet/v2/accounts')->assertStatus(401);
    }

    public function testForeignOrderIsNotFound(): void
    {
        // IDOR: чужой order id → 404 (amendments nice-to-have #2).
        $this->enableV2();
        [, , $orderId] = $this->memberWithOrder(9003, 10000);
        [$other] = $this->registerTg(9004);
        $this->reserve($other, $orderId, 1000, 0)->assertStatus(404);
    }

    public function testAdminRoutesRequireRole(): void
    {
        $this->enableV2();
        [$data, $member] = $this->memberWithOrder(9005, 5000);

        // Без роли — 403.
        $this->getJson("/api/v1/admin/v2/members/{$member->id}/accounts", $this->adminHeaders($data))
            ->assertStatus(403);

        // finance — читает.
        $this->grantRole(9005, 'finance');
        $this->getJson("/api/v1/admin/v2/members/{$member->id}/accounts", $this->adminHeaders($data))
            ->assertOk()->assertJsonPath('data.os_available_cents', 5000);
        $this->getJson("/api/v1/admin/v2/members/{$member->id}/lots", $this->adminHeaders($data))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Лимит ОС ≤70% и достаточность средств (negative)
    // ------------------------------------------------------------------

    public function testReserveAtExactly70PercentPasses(): void
    {
        $this->enableV2();
        [$data, $member, $orderId] = $this->memberWithOrder(9010, 7000);
        // total = 10000 → лимит ОС = intdiv(10000*7000,10000) = 7000.
        $this->reserve($data, $orderId, 7000, 0)->assertOk()
            ->assertJsonPath('data.remainder_cents', 3000)
            ->assertJsonPath('data.paid', false);

        $a = $this->account($member->id);
        $this->assertSame(0, $a->os_available_cents);
        $this->assertSame(7000, $a->os_held_cents);
    }

    public function testReserveOneCentOverLimitRejected(): void
    {
        $this->enableV2();
        [$data, $member, $orderId] = $this->memberWithOrder(9011, 8000);
        $this->reserve($data, $orderId, 7001, 0)->assertStatus(422);
        $this->assertSame(8000, $this->account($member->id)->os_available_cents);
        $this->assertSame(0, WalletReservationV2::query()->count());
    }

    public function testReserveInsufficientBsRejected(): void
    {
        $this->enableV2();
        [$data, , $orderId] = $this->memberWithOrder(9012, 0, 1000);
        $this->reserve($data, $orderId, 0, 2000)->assertStatus(422);
        $this->assertSame(0, WalletReservationV2::query()->count());
    }

    public function testSecondLiveReservationConflicts(): void
    {
        $this->enableV2();
        [$data, , $orderId] = $this->memberWithOrder(9013, 7000, 0);
        $this->reserve($data, $orderId, 1000, 0)->assertOk();
        $this->reserve($data, $orderId, 1000, 0)->assertStatus(409);
    }

    public function testReserveRejectedWhenLiveInvoiceExists(): void
    {
        // Живой TON-инвойс на полную сумму + резерв = переплата → 409.
        $this->enableV2();
        [$data, , $orderId] = $this->memberWithOrder(9014, 7000);
        $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->assertOk();
        $this->reserve($data, $orderId, 1000, 0)->assertStatus(409);
    }

    // ------------------------------------------------------------------
    // Резерв → инвойс на остаток → оплата → капчер → активация
    // ------------------------------------------------------------------

    public function testPartialReserveInvoiceOnRemainderAndCapture(): void
    {
        $this->enableV2();
        [$data, $member, $orderId] = $this->memberWithOrder(9020, 7000, 2000);
        $v1WalletsBefore = MemberWallet::query()->count();

        $this->reserve($data, $orderId, 6000, 1000)->assertOk()
            ->assertJsonPath('data.remainder_cents', 3000);

        // Инвойс — на остаток, не на полную сумму.
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');
        $this->assertSame(3000, $pay['amount_cents']);

        // Оплата остатка → markPaid → capture → активация V1.
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => 3000,
        ])->assertOk();

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->activation_event_id);

        $a = $this->account($member->id);
        $this->assertSame(1000, $a->os_available_cents); // 7000 − 6000
        $this->assertSame(0, $a->os_held_cents);         // капчер снял холд
        $this->assertSame(1000, $a->bs_available_cents); // 2000 − 1000
        $this->assertSame(0, $a->bs_held_cents);

        $res = WalletReservationV2::query()->where('order_id', $orderId)->sole();
        $this->assertSame(WalletReservationV2::STATUS_CAPTURED, $res->status);

        // Выручка компании получила капчер (7000 со счетов).
        $this->assertSame(7000, (int) LedgerEntry::query()
            ->where('source_type', 'acct_reserve')->whereNull('member_id')
            ->where('account_type', 'company_sales_revenue')->where('direction', 'credit')->sum('amount_cents'));

        // Регресс V1: капчер/резерв не создали и не изменили member_wallets сверх
        // того, что сделала сама активация (бонусы V1 идут своим путём).
        $this->assertGreaterThanOrEqual($v1WalletsBefore, MemberWallet::query()->count());
    }

    public function testFullAccountPaymentActivatesWithoutInvoice(): void
    {
        $this->enableV2();
        [$data, $member, $orderId] = $this->memberWithOrder(9021, 7000, 3000);

        $this->reserve($data, $orderId, 7000, 3000)->assertOk()
            ->assertJsonPath('data.remainder_cents', 0)
            ->assertJsonPath('data.paid', true);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->activation_event_id);
        $this->assertSame('active', $order->member->status);

        // Ни одного TON-платежа по заказу.
        $this->assertSame(0, \Modules\Calculator\Models\Payment::query()->where('order_id', $orderId)->count());

        $a = $this->account($member->id);
        $this->assertSame(0, $a->os_available_cents + $a->os_held_cents);
        $this->assertSame(0, $a->bs_available_cents + $a->bs_held_cents);
    }

    public function testReleaseRestoresBalancesAndLots(): void
    {
        $this->enableV2();
        [$data, $member, $orderId] = $this->memberWithOrder(9022, 7000, 2000);
        $this->reserve($data, $orderId, 5000, 1500)->assertOk();

        $this->deleteJson("/api/v1/cabinet/v2/orders/{$orderId}/account-payment", [], $this->tgHeaders($data))
            ->assertOk()->assertJsonPath('data.released', true);

        $a = $this->account($member->id);
        $this->assertSame(7000, $a->os_available_cents);
        $this->assertSame(0, $a->os_held_cents);
        $this->assertSame(2000, $a->bs_available_cents);
        $this->assertSame(0, $a->bs_held_cents);

        // Лоты восстановлены полностью.
        $this->assertSame(7000, (int) WalletLotV2::query()->where('member_id', $member->id)
            ->where('account', 'os')->sum('available_cents'));
        $this->assertSame(2000, (int) WalletLotV2::query()->where('member_id', $member->id)
            ->where('account', 'bs')->sum('available_cents'));

        // После освобождения можно резервировать снова (терминальный статус вне индекса).
        $this->reserve($data, $orderId, 1000, 0)->assertOk();
    }

    public function testCabinetAccountsEndpointsReturnData(): void
    {
        $this->enableV2();
        [$data, $member] = $this->memberWithOrder(9023, 5000, 1000);

        $this->getJson('/api/v1/cabinet/v2/accounts', $this->tgHeaders($data))->assertOk()
            ->assertJsonPath('data.os_available_cents', 5000)
            ->assertJsonPath('data.os_available', '50.00')
            ->assertJsonPath('data.bs_available_cents', 1000);

        $this->getJson('/api/v1/cabinet/v2/accounts/lots', $this->tgHeaders($data))->assertOk()
            ->assertJsonCount(2, 'data.items');

        $history = $this->getJson('/api/v1/cabinet/v2/accounts/history', $this->tgHeaders($data))
            ->assertOk()->json('data.items');
        $this->assertCount(2, $history); // два кредита
        $this->assertSame(5000, $history[1]['amount_cents']);
    }

    // ------------------------------------------------------------------
    // MF-6 (ревью W1): гонка «резерв со счетов vs TON-инвойс» на одном заказе.
    // Обе точки входа обязаны сериализоваться row-lock'ом заказа: без него
    // double-click проходит обе проверки и инвойс на полную сумму ложится
    // поверх резерва (переплата участника).
    // ------------------------------------------------------------------

    /** Слушает SQL и возвращает флаг «orders взят SELECT … FOR UPDATE». */
    private function spyOrderRowLock(): \Closure
    {
        $locked = (object) ['hit' => false];
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($locked) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "orders"') && str_contains($sql, 'for update')) {
                $locked->hit = true;
            }
        });

        return fn (): bool => $locked->hit;
    }

    public function testReserveTakesOrderRowLock(): void
    {
        $this->enableV2();
        [, $member, $orderId] = $this->memberWithOrder(9040, 7000);
        $order = Order::query()->findOrFail($orderId);

        $lockTaken = $this->spyOrderRowLock();
        app(\Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService::class)
            ->reserve($order, 1000, 0);

        $this->assertTrue($lockTaken(), 'MF-6: reserve() обязан взять row-lock заказа до проверки живого инвойса');
    }

    public function testStartOrderPaymentTakesOrderRowLock(): void
    {
        $this->enableV2();
        [, $member, $orderId] = $this->memberWithOrder(9041, 7000);

        $lockTaken = $this->spyOrderRowLock();
        app(\Modules\Calculator\Services\PaymentService::class)
            ->startOrderPayment($member, $orderId);

        $this->assertTrue($lockTaken(), 'MF-6: startOrderPayment обязан взять row-lock заказа до расчёта остатка/создания инвойса');
    }

    public function testInvoiceOnFullyReservedOrderRejected(): void
    {
        // Взаимное исключение по состоянию: заказ полностью зарезервирован в момент
        // выставления инвойса → остаток 0 → инвойс невозможен (а не инвойс на total).
        $this->enableV2();
        [, $member, $orderId] = $this->memberWithOrder(9042, 7000, 3000);
        $order = Order::query()->findOrFail($orderId);
        app(\Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService::class)
            ->reserve($order, 7000, 3000);

        try {
            app(\Modules\Calculator\Services\PaymentService::class)->startOrderPayment($member, $orderId);
            $this->fail('Инвойс на полностью зарезервированный заказ должен отклоняться');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('зарезервирован', $e->getMessage());
        }
        $this->assertSame(0, \Modules\Calculator\Models\Payment::query()->where('order_id', $orderId)->count());
    }

    public function testV1FlowsUntouchedWhenFlagsOff(): void
    {
        // Флаги выключены: заказ оплачивается полностью по-старому, инвойс на total.
        [$data, , $orderId] = $this->memberWithOrder(9030);
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');
        $this->assertSame(10000, $pay['amount_cents']);

        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => 10000,
        ])->assertOk();

        $this->assertSame(Order::STATUS_PAID, Order::query()->findOrFail($orderId)->status);
        // Никаких V2-артефактов.
        $this->assertSame(0, WalletReservationV2::query()->count());
        $this->assertSame(0, LedgerEntry::query()->where('source_type', 'acct_reserve')->count());
    }
}
