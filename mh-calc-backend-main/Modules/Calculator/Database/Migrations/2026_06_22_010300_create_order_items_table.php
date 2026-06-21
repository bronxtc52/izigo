<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Позиции заказа. В MVP (модель A) — одна позиция на заказ (товар = тариф), но таблица
 * хранит снимок цены/имени/PV на момент покупки, чтобы изменение каталога не искажало
 * историю. Мульти-позиционная корзина — задел на 4.2.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->unsignedBigInteger('unit_price_usdt_cents');
            $table->unsignedInteger('pv')->default(0);
            $table->string('name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
