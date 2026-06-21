<?php

namespace Modules\Calculator\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Calculator\Models\Product;

/**
 * Стартовый каталог тарифов-товаров (Фаза 4, модель A). Каждый товар привязан к
 * тарифному пакету (package_id ∈ {1,2,3}), цена — в USDT-центах, pv — отображаемый.
 * Идемпотентно по sku (updateOrCreate), чтобы повторный прогон не плодил дублей.
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
            Product::query()->updateOrCreate(
                ['sku' => $t['sku']],
                [
                    'name' => $t['name'],
                    'description' => "Тариф {$t['name']} — активация пакета, {$t['pv']} PV.",
                    'price_usdt_cents' => $t['price_usdt_cents'],
                    'pv' => $t['pv'],
                    'package_id' => $t['package_id'],
                    'is_active' => true,
                    'sort' => $t['sort'],
                    'stock' => null,
                ],
            );
        }
    }
}
