<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\OrderItem;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Services\Refunds\Exceptions\RefundValidationException;

/**
 * T12: детерминированный план возврата. Строит строки возврата из заказа с
 * ИММУТАБЕЛЬНЫМ снапшотом BV/PV (v2_order_volume_snapshots, DEC-003) — пропорция
 * частичного возврата считается строго по снапшоту (rounding-инвариант тест-плана).
 * НИКОГДА не считает по текущему каталогу/тиру/рангу (CAL-REV-001).
 *
 * Возвращает валидированные строки; qty>ordered / некорректный item → 422.
 */
class ReversalPlanner
{
    /**
     * @param array<int,array{order_item_id:int,qty:int}> $requestedLines для partial;
     *        для full игнорируется (берутся все позиции целиком).
     * @return array{lines:array<int,array{order_item_id:int,qty:int,returned_pv:string,returned_bv_cents:int}>,returned_pv:string,returned_bv_cents:int}
     */
    public function planLines(Order $order, string $kind, array $requestedLines): array
    {
        /** @var array<int,OrderItem> $items */
        $items = OrderItem::query()->where('order_id', $order->id)->get()->keyBy('id')->all();
        if ($items === []) {
            throw new RefundValidationException('У заказа нет позиций');
        }

        // Снимок BV/PV на момент оплаты — по order_item_id.
        $snaps = OrderVolumeSnapshot::query()
            ->where('order_id', $order->id)
            ->get()
            ->keyBy('order_item_id');

        if ($kind === OrderReturn::KIND_FULL) {
            $requestedLines = [];
            foreach ($items as $item) {
                $requestedLines[] = ['order_item_id' => $item->id, 'qty' => (int) $item->qty];
            }
        }

        if ($requestedLines === []) {
            throw new RefundValidationException('Пустой набор строк возврата');
        }

        $lines = [];
        $totalPv = '0.000000';
        $totalBv = 0;
        $seen = [];
        foreach ($requestedLines as $req) {
            $itemId = (int) ($req['order_item_id'] ?? 0);
            $qty = (int) ($req['qty'] ?? 0);

            if (! isset($items[$itemId])) {
                throw new RefundValidationException("Позиция {$itemId} не принадлежит заказу");
            }
            if (isset($seen[$itemId])) {
                throw new RefundValidationException("Позиция {$itemId} указана дважды");
            }
            $seen[$itemId] = true;

            $item = $items[$itemId];
            if ($qty <= 0) {
                throw new RefundValidationException("qty должен быть > 0 (позиция {$itemId})");
            }
            if ($qty > (int) $item->qty) {
                throw new RefundValidationException(
                    "qty {$qty} превышает заказанное {$item->qty} (позиция {$itemId})",
                );
            }

            // Пропорция по снапшоту (per-line), floor по центам.
            $snap = $snaps->get($itemId);
            $itemBv = $snap !== null ? (int) $snap->bv_usd_cents : 0;
            $itemPv = $snap !== null ? (string) $snap->pv : '0.000000';

            $lineBv = (int) $item->qty > 0 ? intdiv($itemBv * $qty, (int) $item->qty) : 0;
            $linePv = (int) $item->qty > 0
                ? bcmul(bcdiv($itemPv, (string) $item->qty, 12), (string) $qty, 6)
                : '0.000000';

            $lines[] = [
                'order_item_id' => $itemId,
                'qty' => $qty,
                'returned_pv' => $linePv,
                'returned_bv_cents' => $lineBv,
            ];
            $totalPv = bcadd($totalPv, $linePv, 6);
            $totalBv += $lineBv;
        }

        return [
            'lines' => $lines,
            'returned_pv' => $totalPv,
            'returned_bv_cents' => $totalBv,
        ];
    }
}
