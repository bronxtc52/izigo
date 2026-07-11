<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): PV-лоты бинара — по одному лоту на (позиция заказа × бинарный
 * предок × сторона). Популяция — ВСЕ binary descendants включая spillover (DEC-055,
 * решение владельца). Лоты бессрочные, FIFO с provenance и reversal-связями
 * (DEC-018 SPEC_DEFAULT). reversal_of_lot_id — закладывает T03, использует T12
 * (amendments MF-8). state=grace_held — задел под T05 (grace CLIENT), логики здесь нет.
 *
 * Инвариант денег/объёма: pv_available + pv_matched + pv_reversed = pv_original —
 * закреплён CHECK-констрейнтом (pgsql).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_pv_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_member_id')->constrained('members');
            $table->string('side', 5); // left|right — сторона ветки владельца, где сидит покупатель
            $table->foreignId('buyer_member_id')->constrained('members');
            $table->foreignId('origin_order_id')->constrained('orders');
            $table->foreignId('origin_order_item_id')->constrained('order_items');
            $table->decimal('pv_original', 18, 6);
            $table->decimal('pv_available', 18, 6);
            $table->decimal('pv_matched', 18, 6)->default(0);
            $table->decimal('pv_reversed', 18, 6)->default(0);
            $table->unsignedBigInteger('bv_usd_cents_original'); // BV-provenance строки снапшота
            $table->unsignedBigInteger('policy_version_id');
            $table->string('state', 12)->default('free'); // free|grace_held|exhausted|reversed
            $table->foreignId('reversal_of_lot_id')->nullable()->constrained('v2_pv_lots');
            $table->timestamp('occurred_at'); // момент PAID — порядок FIFO
            $table->timestamps();

            // Идемпотентность инжеста (AT-IDEM-001): повторный markPaid не плодит лоты.
            $table->unique(
                ['origin_order_item_id', 'owner_member_id', 'side'],
                'v2_pv_lots_item_owner_side_uq'
            );
            // FIFO-выборка free-лотов стороны.
            $table->index(['owner_member_id', 'side', 'state', 'occurred_at'], 'v2_pv_lots_fifo_ix');
            $table->index('origin_order_id', 'v2_pv_lots_origin_order_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE v2_pv_lots ADD CONSTRAINT v2_pv_lots_pv_balance_ck '
                . 'CHECK (pv_available + pv_matched + pv_reversed = pv_original)'
            );
            DB::statement(
                "ALTER TABLE v2_pv_lots ADD CONSTRAINT v2_pv_lots_side_ck CHECK (side IN ('left', 'right'))"
            );
            DB::statement(
                "ALTER TABLE v2_pv_lots ADD CONSTRAINT v2_pv_lots_state_ck "
                . "CHECK (state IN ('free', 'grace_held', 'exhausted', 'reversed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_pv_lots');
    }
};
