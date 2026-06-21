<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Каталог товаров (Фаза 4, модель A «товары = тарифы»). Товар несёт цену в USDT-центах
 * и привязан к тарифному пакету (package_id ∈ calculator_packages): оплаченный заказ
 * активирует именно этот пакет через существующий ActivationService.
 *
 * Поле pv здесь — отображаемое/отчётное (для витрины и total_pv заказа). Комп-математика
 * берёт PV из доменного плана по package_id (см. Вариант A в plan.md), не из этого поля.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_usdt_cents'); // цена в USDT-центах
            $table->unsignedInteger('pv')->default(0);      // отображаемый PV (points), не источник комп-PV
            $table->foreignId('package_id')->constrained('calculator_packages'); // тариф для активации
            $table->string('sku')->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('sort')->default(0)->index();
            $table->integer('stock')->nullable(); // null = не лимитируем
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
