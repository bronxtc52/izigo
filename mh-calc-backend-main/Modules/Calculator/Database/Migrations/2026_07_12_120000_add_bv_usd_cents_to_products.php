<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): BV тарифа в USD-центах, раздельно от PV (DEC-002/DEC-016).
 * NULL => BV = цене товара (price_usdt_cents) — дефолт Гейта A «BV = 100% цены»;
 * существующий целочисленный products.pv остаётся отображаемым полем, источником
 * для V2 служит immutable-снапшот заказа (v2_order_volume_snapshots).
 * Backfill не нужен: NULL трактуется как «BV = цена» на чтении.
 * Слот миграций T03 — 2026_07_12_12xxxx (docs/mh-full-plan-migration-ledger.md).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('bv_usd_cents')->nullable()->after('pv');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('bv_usd_cents');
        });
    }
};
