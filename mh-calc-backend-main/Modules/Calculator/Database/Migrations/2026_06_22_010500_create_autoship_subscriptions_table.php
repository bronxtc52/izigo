<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autoship-подписки (Фаза 4, S6). Периодическая ре-покупка тарифа со списанием с
 * внутреннего USDT-баланса. retry_stage — ступень повторов при нехватке средств
 * (0 → 3 → 7 → 14 дней), после исчерпания подписка ставится на паузу.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('autoship_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('package_id')->constrained('calculator_packages');
            $table->unsignedInteger('interval_days');
            $table->timestamp('next_charge_at');
            $table->string('status', 12)->default('active'); // active|paused|cancelled
            $table->unsignedSmallInteger('retry_stage')->default(0); // 0|3|7|14
            $table->timestamp('last_charge_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_charge_at']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autoship_subscriptions');
    }
};
