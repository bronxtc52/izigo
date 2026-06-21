<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказы (Фаза 4). Под модель A заказ = покупка одного тарифа: несёт package_id,
 * который активируется при оплате. Суммы — целые USDT-центы. Жизненный цикл:
 * pending_payment → paid → processing → shipped → delivered,
 * ветки cancelled / refunded. activation_event_id заполняется после успешной активации.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('calculator_packages'); // активируемый тариф
            $table->unsignedBigInteger('total_usdt_cents');
            $table->unsignedInteger('total_pv')->default(0);
            $table->string('status', 24)->default('pending_payment');
            $table->text('shipping_info')->nullable();
            $table->string('tracking_no')->nullable();
            $table->foreignId('activation_event_id')->nullable()
                ->constrained('activation_events')->nullOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
