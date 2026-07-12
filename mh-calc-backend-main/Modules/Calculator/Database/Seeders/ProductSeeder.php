<?php

namespace Modules\Calculator\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Calculator\Models\Product;

/**
 * Стартовый каталог тарифов-товаров (Фаза 4, модель A). Каждый товар привязан к
 * тарифному пакету (package_id ∈ {1,2,3}), цена — в USDT-центах, pv — отображаемый.
 * Идемпотентно по sku, повторный прогон не плодит дублей.
 *
 * ВАЖНО (mh-full-plan cutover): `pv`/`price_usdt_cents`/`is_active` СУЩЕСТВУЮЩЕГО тарифа
 * сидер НЕ перетирает — по образцу FeatureFlagSeeder (не сбивает админские/рантайм-правки).
 * Иначе cutover-правка Bronze→100 PV (BronzeTariffCutoverService) тихо откатывалась бы к 90
 * на следующем деплое/рестарте (start.sh гоняет сидер каждый раз). Значения ниже — только
 * дефолты ПЕРВИЧНОГО создания; менять живой тариф — через cutover/админку, не сидером.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $tariffs = [
            ['sku' => 'TARIFF-BRONZE', 'name' => 'Bronze', 'package_id' => 1, 'pv' => 90,  'price_usdt_cents' => 9000,  'sort' => 1],
            ['sku' => 'TARIFF-SILVER', 'name' => 'Silver', 'package_id' => 2, 'pv' => 180, 'price_usdt_cents' => 18000, 'sort' => 2],
            ['sku' => 'TARIFF-GOLD',   'name' => 'Gold',   'package_id' => 3, 'pv' => 540, 'price_usdt_cents' => 54000, 'sort' => 3],
        ];

        foreach ($tariffs as $t) {
            // firstOrNew по sku: существующую строку НЕ трогаем в части pv/price/is_active
            // (рантайм-истина сохраняется); косметику (name/description/sort/package_id)
            // держим в актуальном состоянии; создаём отсутствующий тариф с дефолтами.
            $product = Product::query()->firstOrNew(['sku' => $t['sku']]);

            $product->name = $t['name'];
            $product->description = "Тариф {$t['name']} — активация пакета, {$t['pv']} PV.";
            $product->package_id = $t['package_id'];
            $product->sort = $t['sort'];

            if (!$product->exists) {
                // Только при первичном создании — дефолтные pv/price/активность/сток.
                $product->pv = $t['pv'];
                $product->price_usdt_cents = $t['price_usdt_cents'];
                $product->is_active = true;
                $product->stock = null;
            }

            $product->save();
        }
    }
}
