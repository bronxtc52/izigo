<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Оформление заказа в кабинете (Фаза 4, S1 / US-2): заказ создаётся в pending_payment
 * с корректными суммами и package_id тарифа; идемпотентность; изоляция между партнёрами.
 */
class OrderCabinetTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'name' => 'Silver',
            'price_usdt_cents' => 18000,
            'pv' => 180,
            'package_id' => 2,
            'sku' => 'TARIFF-SILVER',
            'is_active' => true,
            'sort' => 2,
        ], $overrides));
    }

    public function testCreateOrderStartsPendingPaymentWithTotals(): void
    {
        $product = $this->makeProduct();
        [$data] = $this->registerTg(310, name: 'Owner');

        $res = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id], $this->tgHeaders($data))->assertOk();

        $res->assertJsonPath('data.status', Order::STATUS_PENDING_PAYMENT);
        $this->assertSame(18000, $res->json('data.total_usdt_cents'));
        $this->assertSame(180, $res->json('data.total_pv'));
        $this->assertSame(2, $res->json('data.package_id'));
        $this->assertSame('Silver', $res->json('data.items.0.name'));
        $this->assertSame(1, Order::count());
    }

    public function testCreateOrderIsIdempotentByKey(): void
    {
        $product = $this->makeProduct();
        [$data] = $this->registerTg(320, name: 'Owner');

        $first = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id, 'idempotency_key' => 'ord-1'], $this->tgHeaders($data))->json('data.id');
        $second = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id, 'idempotency_key' => 'ord-1'], $this->tgHeaders($data))->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());
    }

    public function testInactiveProductRejected(): void
    {
        $product = $this->makeProduct(['is_active' => false]);
        [$data] = $this->registerTg(330, name: 'Owner');

        $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id], $this->tgHeaders($data))->assertStatus(404);
        $this->assertSame(0, Order::count());
    }

    public function testOrderIsolatedBetweenPartners(): void
    {
        $product = $this->makeProduct();
        [$ownerData, $ownerRef] = $this->registerTg(340, name: 'Owner');
        [$bData] = $this->registerTg(341, $ownerRef, 'B');

        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $product->id], $this->tgHeaders($ownerData))->json('data.id');

        // B не видит чужой заказ.
        $this->getJson("/api/v1/cabinet/orders/{$orderId}", $this->tgHeaders($bData))->assertStatus(404);
        $this->assertEmpty($this->getJson('/api/v1/cabinet/orders', $this->tgHeaders($bData))->json('data'));
    }
}
