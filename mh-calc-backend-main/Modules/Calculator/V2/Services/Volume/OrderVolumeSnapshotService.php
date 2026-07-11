<?php

namespace Modules\Calculator\V2\Services\Volume;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;

/**
 * T03: immutable BV/PV-снапшот заказа на моменте PAID (DEC-003).
 *
 * По каждой позиции: pv = item.pv × qty (decimal 18,6);
 * bv = qty × (product.bv_usd_cents ?? item.unit_price_usdt_cents) — BV тарифа,
 * а при NULL «BV = цене», причём цена берётся из СНИМКА позиции (unit_price
 * зафиксирован при создании заказа), поэтому смена цены товара после PAID
 * снапшот не меняет. Идемпотентно: insertOrIgnore по unique(order_item_id).
 */
class OrderVolumeSnapshotService
{
    public function __construct(private readonly PolicyVersionIdProvider $policyVersions)
    {
    }

    /**
     * @return Collection<int, OrderVolumeSnapshot> все снапшоты заказа (созданные + существующие)
     */
    public function captureOnPaid(Order $order): Collection
    {
        $paidAt = now();
        $policyVersionId = $this->policyVersions->forDate($paidAt);

        $items = $order->items()->get();
        $bvByProduct = Product::query()
            ->whereIn('id', $items->pluck('product_id')->all())
            ->pluck('bv_usd_cents', 'id');

        foreach ($items as $item) {
            $bvUnit = $bvByProduct[$item->product_id] ?? null;
            DB::table('v2_order_volume_snapshots')->insertOrIgnore([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'member_id' => $order->member_id,
                'pv' => bcmul((string) $item->pv, (string) $item->qty, 6),
                'bv_usd_cents' => $item->qty * (int) ($bvUnit ?? $item->unit_price_usdt_cents),
                'policy_version_id' => $policyVersionId,
                'paid_at' => $paidAt,
                'created_at' => $paidAt,
            ]);
        }

        return OrderVolumeSnapshot::query()->where('order_id', $order->id)->get();
    }
}
