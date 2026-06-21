<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Product;
use RuntimeException;

/**
 * Управление каталогом из админки (Фаза 4). «Удаление» — архивирование
 * (is_active=false), чтобы не рвать ссылки из истории заказов.
 */
class ProductAdminService
{
    private CatalogService $catalog;

    public function __construct(CatalogService $catalog)
    {
        $this->catalog = $catalog;
    }

    /** Все товары (включая архивные) для админ-списка. */
    public function listAll(): array
    {
        return Product::query()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $p) => $this->catalog->serialize($p))
            ->all();
    }

    public function create(array $data): array
    {
        $product = Product::query()->create($data);

        return $this->catalog->serialize($product);
    }

    public function update(int $id, array $data): array
    {
        $product = Product::query()->find($id);
        if ($product === null) {
            throw new RuntimeException('Товар не найден');
        }
        $product->update($data);

        return $this->catalog->serialize($product->fresh());
    }

    /** Архивировать товар (is_active=false). */
    public function archive(int $id): array
    {
        $product = Product::query()->find($id);
        if ($product === null) {
            throw new RuntimeException('Товар не найден');
        }
        $product->update(['is_active' => false]);

        return $this->catalog->serialize($product->fresh());
    }
}
