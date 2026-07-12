<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T02: резерв субсчетов под оплату заказа (ОС ≤70% + БС), спека 06.
 * «Один живой (reserved) резерв на заказ» — партиал-unique индекс по образцу
 * payments_one_live_pending_per_order (2026_07_02). Терминальные статусы
 * (captured | released | expired) из индекса выпадают — заказ можно резервировать заново.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_wallet_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedBigInteger('os_cents')->default(0);
            $table->unsignedBigInteger('bs_cents')->default(0);
            $table->string('status', 12)->default('reserved'); // reserved|captured|released|expired
            $table->timestamp('expires_at')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['member_id', 'status']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS v2_wallet_reservations_one_live_per_order '
                . 'ON v2_wallet_reservations (order_id) '
                . "WHERE status = 'reserved'"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS v2_wallet_reservations_one_live_per_order');
        }
        Schema::dropIfExists('v2_wallet_reservations');
    }
};
