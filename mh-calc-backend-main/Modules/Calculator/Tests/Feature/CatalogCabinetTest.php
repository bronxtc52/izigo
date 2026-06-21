<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Витрина каталога в кабинете (Фаза 4, S1 / US-1): только активные товары,
 * доступ лишь по Telegram initData.
 */
class CatalogCabinetTest extends TestCase
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
            'name' => 'Bronze',
            'description' => 'Тариф Bronze',
            'price_usdt_cents' => 9000,
            'pv' => 90,
            'package_id' => 1,
            'sku' => 'TARIFF-BRONZE',
            'is_active' => true,
            'sort' => 1,
        ], $overrides));
    }

    public function testCatalogReturnsOnlyActiveProducts(): void
    {
        $this->makeProduct(['sku' => 'TARIFF-BRONZE', 'sort' => 1]);
        $this->makeProduct(['sku' => 'TARIFF-OLD', 'name' => 'Archived', 'is_active' => false, 'sort' => 9]);

        [$data] = $this->registerTg(300, name: 'Owner');

        $res = $this->getJson('/api/v1/cabinet/catalog', $this->tgHeaders($data))->assertOk();
        $items = $res->json('data');

        $this->assertCount(1, $items);
        $this->assertSame('TARIFF-BRONZE', $items[0]['sku']);
        $this->assertSame(9000, $items[0]['price_usdt_cents']);
        $this->assertSame(1, $items[0]['package_id']);
    }

    public function testCatalogRequiresTelegramInitData(): void
    {
        $this->makeProduct();

        $this->getJson('/api/v1/cabinet/catalog', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }
}
