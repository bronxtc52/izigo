<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Управление каталогом из админки (Фаза 4, S1 / US-10): создание/список/архив,
 * RBAC (owner/support), архив скрывает товар с витрины.
 *
 * Owner назначается только по config calculator.owner_telegram_ids — в тестах роль
 * выдаём явно через grantRole().
 */
class ProductAdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Зарегистрировать участника с ролью support, вернуть его initData. */
    private function support(int $tg, string $name): string
    {
        [$data] = $this->registerTg($tg, name: $name);
        $this->grantRole($tg, 'support');

        return $data;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Bronze',
            'description' => 'Тариф Bronze',
            'price_usdt_cents' => 9000,
            'pv' => 90,
            'package_id' => 1,
            'sku' => 'TARIFF-BRONZE',
            'sort' => 1,
        ], $overrides);
    }

    public function testSupportCanCreateAndListProduct(): void
    {
        $support = $this->support(400, 'Support');

        $this->postJson('/api/v1/admin/products', $this->payload(), $this->tgHeaders($support))
            ->assertOk()->assertJsonPath('data.sku', 'TARIFF-BRONZE');

        $list = $this->getJson('/api/v1/admin/products', $this->tgHeaders($support))->assertOk();
        $this->assertCount(1, $list->json('data'));
        $this->assertSame(1, Product::count());
    }

    public function testNonRoleMemberForbidden(): void
    {
        [$plainData] = $this->registerTg(411, name: 'Plain'); // без ролей и не owner

        $this->getJson('/api/v1/admin/products', $this->tgHeaders($plainData))->assertStatus(403);
        $this->postJson('/api/v1/admin/products', $this->payload(), $this->tgHeaders($plainData))->assertStatus(403);
    }

    public function testArchiveHidesFromCatalog(): void
    {
        $support = $this->support(420, 'Support');
        $created = $this->postJson('/api/v1/admin/products', $this->payload(), $this->tgHeaders($support))
            ->assertOk()->json('data.id');

        $this->deleteJson("/api/v1/admin/products/{$created}", [], $this->tgHeaders($support))
            ->assertOk()->assertJsonPath('data.is_active', false);

        // С витрины пропал.
        $this->assertEmpty($this->getJson('/api/v1/cabinet/catalog', $this->tgHeaders($support))->json('data'));
    }
}
