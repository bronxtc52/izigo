<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\OrderItem;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\ActivationService;
use RuntimeException;

/**
 * Оформление и просмотр заказов (Фаза 4, S1). Под модель A заказ = покупка одного
 * тарифа: package_id берётся из товара и активируется позже при оплате (S3/S4).
 * Денег здесь нет — заказ создаётся в статусе pending_payment.
 */
class OrderService
{
    public function __construct(
        private readonly ActivationService $activation,
    ) {
    }

    /**
     * Создать заказ из товара. Идемпотентно по idempotency_key в рамках участника:
     * повтор того же ключа возвращает уже созданный заказ, а не плодит дубль.
     */
    public function create(Member $member, int $productId, int $qty = 1, ?string $idempotencyKey = null): array
    {
        if ($qty < 1) {
            throw new RuntimeException('Количество должно быть ≥ 1');
        }

        if ($idempotencyKey !== null) {
            $existing = Order::query()
                ->where('member_id', $member->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $this->serialize($existing);
            }
        }

        $product = Product::query()->where('id', $productId)->where('is_active', true)->first();
        if ($product === null) {
            throw new RuntimeException('Товар не найден или недоступен');
        }

        $unit = $product->price_usdt_cents;
        $order = DB::transaction(function () use ($member, $product, $qty, $unit, $idempotencyKey) {
            $order = Order::query()->create([
                'member_id' => $member->id,
                'package_id' => $product->package_id,
                'total_usdt_cents' => $unit * $qty,
                'total_pv' => $product->pv * $qty,
                'status' => Order::STATUS_PENDING_PAYMENT,
                'idempotency_key' => $idempotencyKey,
            ]);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_price_usdt_cents' => $unit,
                'pv' => $product->pv,
                'name_snapshot' => $product->name,
            ]);

            return $order;
        });

        return $this->serialize($order->fresh('items'));
    }

    /**
     * Перевести заказ в paid по факту оплаты (вызывается из PaymentService внутри
     * транзакции). Идемпотентно: переводит только из pending_payment. По оплаченному
     * заказу запускает активацию тарифа (модель A) — существующий ActivationService
     * пересчитывает сеть и пишет дельта-проводки в ledger. Активация идемпотентна по
     * ключу "order:{id}", поэтому повтор webhook не задваивает начисления.
     */
    public function markPaid(int $orderId): void
    {
        $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null || $order->status !== Order::STATUS_PENDING_PAYMENT) {
            return;
        }

        $order->status = Order::STATUS_PAID;
        $order->save();

        $event = $this->activation->activate($order->member_id, $order->package_id, "order:{$order->id}");

        $order->activation_event_id = $event->id;
        $order->save();
    }

    /** Заказы участника, новые сверху. */
    public function listForMember(Member $member): array
    {
        return Order::query()
            ->where('member_id', $member->id)
            ->with('items')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $o) => $this->serialize($o))
            ->all();
    }

    /** Все заказы для админки (фильтр по статусу), новые сверху. */
    public function listForAdmin(?string $status = null): array
    {
        return Order::query()
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->with('items')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Order $o) => $this->serialize($o))
            ->all();
    }

    /**
     * Сменить статус исполнения заказа из админки (S5). Разрешённые цели —
     * processing|shipped|delivered|cancelled|refunded. Фулфилмент-статусы требуют, чтобы
     * заказ был оплачен; cancelled/refunded допускаются и из pending_payment.
     */
    public function setStatus(int $orderId, string $status, ?string $trackingNo = null): array
    {
        $fulfilment = [Order::STATUS_PROCESSING, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED];
        $terminal = [Order::STATUS_CANCELLED, Order::STATUS_REFUNDED];
        if (!in_array($status, [...$fulfilment, ...$terminal], true)) {
            throw new RuntimeException('Недопустимый статус заказа');
        }

        $order = Order::query()->where('id', $orderId)->first();
        if ($order === null) {
            throw new RuntimeException('Заказ не найден');
        }
        if (in_array($status, $fulfilment, true) && $order->status === Order::STATUS_PENDING_PAYMENT) {
            throw new RuntimeException('Нельзя исполнять неоплаченный заказ');
        }

        $order->status = $status;
        if ($trackingNo !== null) {
            $order->tracking_no = $trackingNo;
        }
        $order->save();

        return $this->serialize($order->fresh('items'));
    }

    /** Один заказ участника (404 при чужом/несуществующем). */
    public function getForMember(Member $member, int $orderId): array
    {
        $order = Order::query()
            ->where('member_id', $member->id)
            ->where('id', $orderId)
            ->with('items')
            ->first();
        if ($order === null) {
            throw new RuntimeException('Заказ не найден');
        }

        return $this->serialize($order);
    }

    public function serialize(Order $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'package_id' => $order->package_id,
            'total_usdt_cents' => $order->total_usdt_cents,
            'total_pv' => $order->total_pv,
            'tracking_no' => $order->tracking_no,
            'shipping_info' => $order->shipping_info,
            'created_at' => optional($order->created_at)->toIso8601String(),
            'items' => $order->items->map(fn (OrderItem $i) => [
                'product_id' => $i->product_id,
                'name' => $i->name_snapshot,
                'qty' => $i->qty,
                'unit_price_usdt_cents' => $i->unit_price_usdt_cents,
                'pv' => $i->pv,
            ])->all(),
        ];
    }
}
