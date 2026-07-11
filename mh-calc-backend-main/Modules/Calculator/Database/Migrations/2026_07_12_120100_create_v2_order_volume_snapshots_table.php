<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): immutable BV/PV-снапшот позиции заказа на момент PAID (DEC-003).
 * Одна строка на order_item (unique) — идемпотентность повторного markPaid/webhook.
 * Контракт-потребители: T05 (personal PV), T07 (реферальная база BV).
 * PV — decimal(18,6) (amendments nice-to-have #3), деньги — integer USD-центы.
 * Строка НЕ обновляется после создания (нет updated_at; guard в сервисе).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_order_volume_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('order_item_id')->unique()->constrained('order_items');
            $table->foreignId('member_id')->constrained('members');
            $table->decimal('pv', 18, 6);
            $table->unsignedBigInteger('bv_usd_cents');
            // FK на v2_policy_versions добавит T01 своей миграцией (таблицы ещё нет в этой волне).
            $table->unsignedBigInteger('policy_version_id');
            $table->timestamp('paid_at');
            $table->timestamp('created_at');

            $table->index('order_id', 'v2_ovs_order_ix');
            $table->index(['member_id', 'paid_at'], 'v2_ovs_member_paid_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_volume_snapshots');
    }
};
