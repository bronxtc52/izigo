<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Product;

/**
 * Витрина каталога (Фаза 4). Отдаёт активные товары партнёру в кабинет.
 */
class CatalogService
{
    /** Активные товары для витрины, отсортированы по sort. */
    public function listActive(): array
    {
        return Product::query()
            ->where('is_active', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $p) => $this->serialize($p))
            ->all();
    }

    public function serialize(Product $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'price_usdt_cents' => $p->price_usdt_cents,
            'pv' => $p->pv,
            'package_id' => $p->package_id,
            'sku' => $p->sku,
            'is_active' => $p->is_active,
            'sort' => $p->sort,
            'stock' => $p->stock,
        ];
    }
}
