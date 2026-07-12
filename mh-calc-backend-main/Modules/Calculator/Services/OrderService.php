<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\OrderItem;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Services\LeadService;
use Modules\Calculator\V2\Contracts\PaidOrderV2Pipeline;
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
        private readonly LeadService $leads,
        private readonly FeatureFlagService $flags,
        private readonly PaidOrderV2Pipeline $v2Pipeline,
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
     * Заказ ЛИДА (ещё не купил, вне дерева): member_id=null, lead_id указывает на лида.
     * При подтверждённой оплате лид промоутится в Member и member_id заполняется
     * (см. markPaid). Идемпотентность — по lead_id + idempotency_key.
     */
    public function createForLead(Lead $lead, int $productId, int $qty = 1, ?string $idempotencyKey = null): array
    {
        if ($qty < 1) {
            throw new RuntimeException('Количество должно быть ≥ 1');
        }

        if ($idempotencyKey !== null) {
            $existing = Order::query()
                ->where('lead_id', $lead->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return $this->serialize($existing->fresh('items'));
            }
        }

        $product = Product::query()->where('id', $productId)->where('is_active', true)->first();
        if ($product === null) {
            throw new RuntimeException('Товар не найден или недоступен');
        }

        $unit = $product->price_usdt_cents;
        $order = DB::transaction(function () use ($lead, $product, $qty, $unit, $idempotencyKey) {
            $order = Order::query()->create([
                'member_id' => null,
                'lead_id' => $lead->id,
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
        if ($order === null) {
            // Платёж финализирован, а заказа нет (осиротевший/удалённый) — деньги приняты без
            // фулфилмента. Не тихо: в лог + Sentry для ручного разбора/возврата.
            Log::error("markPaid: заказ {$orderId} не найден для подтверждённого платежа");
            \Sentry\captureMessage(
                "markPaid: заказ {$orderId} не найден для подтверждённого платежа (деньги без фулфилмента)",
                \Sentry\Severity::error()
            );

            return;
        }
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            // Заказ уже не ждёт оплаты (отменён / оплачен другим инвойсом). Подтверждённый
            // платёж не активирует его повторно, но деньги приняты без фулфилмента — сигналим
            // в лог + Sentry (раньше был тихий no-op → потеря денег незаметна).
            Log::warning("markPaid: заказ {$orderId} не в pending_payment (status={$order->status}) — платёж принят без фулфилмента", [
                'order_id' => $orderId,
                'status' => $order->status,
            ]);
            \Sentry\captureMessage(
                sprintf('markPaid: заказ %d уже не ожидает оплаты (status=%s) — принятый платёж без фулфилмента', $orderId, $order->status),
                \Sentry\Severity::warning()
            );

            return;
        }

        // Промоушн лида → Member (первая покупка). Лид ставится в бинар-дерево под
        // замкнутого спонсора; member_id заказа заполняется. Спонсор зафиксирован навсегда.
        if ($order->member_id === null && $order->lead_id !== null) {
            $lead = Lead::query()->where('id', $order->lead_id)->first();
            if ($lead !== null) {
                $member = $this->leads->promote($lead);
                $order->member_id = $member->id;
                $order->lead_id = null; // лид промоутнут (запись удалена)
            }
        }

        if ($order->member_id === null) {
            // Лид-заказ, у которого не осталось ни участника, ни лида (запись удалена без
            // backfill — напр. лид без pending-платежа открепился). Поставить в дерево некого:
            // не активируем «в никуда», откатываемся (платёж останется pending, TTL → expired).
            // Защитный инвариант: в норме недостижим (promote переносит все заказы лида на
            // участника, а expireDue/attachOrReattach берегут лидов с pending-платежом).
            throw new RuntimeException("Заказ {$order->id}: нет участника для активации");
        }

        $order->status = Order::STATUS_PAID;
        $order->save();

        // >>> V2 T02 (mh-full-plan): capture живого резерва счетов ОС/БС — ДО активации.
        // Дремлет за фиче-флагом mh_plan_v2_engine (deny-by-default до cutover T15);
        // нет живого резерва — no-op. Единый порядок локов: advisory-lock активаций
        // берём ДО ledger-записей capture (жёсткая рамка проекта).
        if (app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->isEnabled('mh_plan_v2_engine')) {
            $this->activation->acquireActivationLock();
            app(\Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService::class)->capture($order->id);
        }
        // <<< V2 T02

        // Имя купленного товара (снимок на момент покупки) — для текста уведомления об активации,
        // чтобы показать партнёру название его товара, а не легаси-имя пакета (Bronze/Silver/Gold).
        $displayName = OrderItem::query()->where('order_id', $order->id)->value('name_snapshot');
        $event = $this->activation->activate($order->member_id, $order->package_id, "order:{$order->id}", $displayName);

        $order->activation_event_id = $event->id;
        $order->save();

        // >>> V2 T03: единая точка пост-оплатных V2-хуков (PaidOrderV2Pipeline) — в ТОЙ ЖЕ
        // транзакции оплаты, под advisory-lock, взятым activate() выше. Шаги пайплайна сами
        // гейтятся своими флагами; внешний гейт держит V1 hot-path нетронутым, пока V2 выключен.
        if ($this->flags->isEnabled('mh_plan_v2_engine') || $this->flags->isEnabled('mh_v2_volumes')) {
            $this->v2Pipeline->runFor($order->id);
        }
        // <<< V2 T03
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
        // T12 guard: при включённых возвратах V2 прямой перевод ОПЛАЧЕННОГО заказа в
        // refunded мимо RefundService запрещён — иначе финансовое сторно (реверс
        // бонусов/лотов) не выполнится. Возврат оформляется через admin/v2/refunds.
        if ($status === Order::STATUS_REFUNDED
            && $order->status === Order::STATUS_PAID
            && $this->flags->isEnabled('mh_v2_refunds')
        ) {
            throw new RuntimeException('Возврат оплаченного заказа — только через RefundService (admin/v2/refunds)');
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
