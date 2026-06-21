<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * Управление исполнением заказов из админки (Фаза 4, S5 / US-5): смена статуса и
 * трек-номера, видимость партнёру, запрет фулфилмента неоплаченного, RBAC.
 */
class OrderAdminTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    private function order(Member $member, string $status): Order
    {
        return Order::query()->create([
            'member_id' => $member->id,
            'package_id' => 1,
            'total_usdt_cents' => 9000,
            'total_pv' => 90,
            'status' => $status,
        ]);
    }

    public function testSupportChangesStatusAndPartnerSeesIt(): void
    {
        [$buyerData] = $this->registerTg(700, name: 'Buyer');
        [$supportData] = $this->registerTg(701, name: 'Support');
        $this->grantRole(701, 'support');

        $order = $this->order($this->memberByTg(700), Order::STATUS_PAID);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status",
            ['status' => Order::STATUS_SHIPPED, 'tracking_no' => 'TRK-1'], $this->tgHeaders($supportData))
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_SHIPPED)
            ->assertJsonPath('data.tracking_no', 'TRK-1');

        // Партнёр видит обновлённый статус и трек.
        $view = $this->getJson("/api/v1/cabinet/orders/{$order->id}", $this->tgHeaders($buyerData))->json('data');
        $this->assertSame(Order::STATUS_SHIPPED, $view['status']);
        $this->assertSame('TRK-1', $view['tracking_no']);
    }

    public function testCannotFulfilUnpaidOrder(): void
    {
        [$buyerData] = $this->registerTg(710, name: 'Buyer');
        [$supportData] = $this->registerTg(711, name: 'Support');
        $this->grantRole(711, 'support');

        $order = $this->order($this->memberByTg(710), Order::STATUS_PENDING_PAYMENT);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status",
            ['status' => Order::STATUS_PROCESSING], $this->tgHeaders($supportData))
            ->assertStatus(404);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, Order::find($order->id)->status);
    }

    public function testInvalidStatusRejected(): void
    {
        [$buyerData] = $this->registerTg(720, name: 'Buyer');
        [$supportData] = $this->registerTg(721, name: 'Support');
        $this->grantRole(721, 'support');

        $order = $this->order($this->memberByTg(720), Order::STATUS_PAID);

        $this->patchJson("/api/v1/admin/orders/{$order->id}/status",
            ['status' => 'flying'], $this->tgHeaders($supportData))->assertStatus(404);
    }

    public function testNonRoleForbidden(): void
    {
        [$plainData] = $this->registerTg(730, name: 'Plain');

        $this->getJson('/api/v1/admin/orders', $this->tgHeaders($plainData))->assertStatus(403);
    }
}
