<?php

namespace Modules\Calculator\V2\Services\Cutover;

use Modules\Calculator\Models\Product;

/**
 * mh-full-plan T15 (W6): правка тарифа Bronze на момент cutover.
 *
 * Решение владельца (dec-triage 2026-07-12): Bronze поднимается 90 PV / 90 USDT →
 * 100 PV / 100 USDT, чтобы КАЖДЫЙ покупатель проходил порог 100 PV (CLIENT/START)
 * плана V2. Сохраняется 1 PV = 1 USD. Идемпотентно (повторный apply — no-op).
 */
class BronzeTariffCutoverService
{
    public const SKU = 'TARIFF-BRONZE';
    public const TARGET_PV = 100;
    public const TARGET_PRICE_CENTS = 10000; // 100 USDT в центах (1 PV = 1 USD)

    /** Read-only: нужна ли правка (dry-run). */
    public function needsUpdate(): bool
    {
        $p = $this->product();

        return $p !== null
            && ((int) $p->pv !== self::TARGET_PV || (int) $p->price_usdt_cents !== self::TARGET_PRICE_CENTS);
    }

    /** @return array{sku:string,pv:int,price_usdt_cents:int}|null текущее состояние тарифа */
    public function current(): ?array
    {
        $p = $this->product();
        if ($p === null) {
            return null;
        }

        return ['sku' => self::SKU, 'pv' => (int) $p->pv, 'price_usdt_cents' => (int) $p->price_usdt_cents];
    }

    /**
     * Поднять Bronze → 100 PV / 100 USDT (идемпотентно). Возвращает before/after,
     * либо null, если тарифа Bronze в каталоге нет.
     *
     * @return array{before:array{pv:int,price_usdt_cents:int},after:array{pv:int,price_usdt_cents:int}}|null
     */
    public function apply(): ?array
    {
        $p = Product::query()->where('sku', self::SKU)->lockForUpdate()->first();
        if ($p === null) {
            return null;
        }

        $before = ['pv' => (int) $p->pv, 'price_usdt_cents' => (int) $p->price_usdt_cents];

        $p->pv = self::TARGET_PV;
        $p->price_usdt_cents = self::TARGET_PRICE_CENTS;
        // BV тарифа (V2, T03): NULL => BV = цене; если задан явно — держим = стоимости.
        if ($p->bv_usd_cents !== null) {
            $p->bv_usd_cents = self::TARGET_PRICE_CENTS;
        }
        $p->save();

        return ['before' => $before, 'after' => ['pv' => self::TARGET_PV, 'price_usdt_cents' => self::TARGET_PRICE_CENTS]];
    }

    private function product(): ?Product
    {
        return Product::query()->where('sku', self::SKU)->first();
    }
}
