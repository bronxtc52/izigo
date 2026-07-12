<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T12: строки возврата (v2_order_return_lines) — по позиции заказа. qty<=ordered.
 * returned_pv / returned_bv_cents — ИММУТАБЕЛЬНЫЙ снапшот из OrderItem по DEC-003
 * (не из текущего каталога): частичный возврат считает пропорцию строго по этому
 * снапшоту, без дрейфа центов (rounding-инвариант тест-плана T12).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_order_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('v2_order_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items');
            $table->integer('qty');
            $table->decimal('returned_pv', 18, 6);
            $table->bigInteger('returned_bv_cents');
            $table->timestamps();

            $table->unique(['return_id', 'order_item_id'], 'v2_order_return_lines_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_return_lines');
    }
};
