<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): построчное потребление лотов матчем — provenance для
 * explainability (DEC-054) и reversals (T12). BV аллокации — пропорционально
 * потреблённому PV от ОСТАТКА BV лота, округление largest-remainder внутри
 * стороны прогона: сумма аллокаций стороны = BV-итог стороны, ни один цент
 * не теряется и не задваивается за жизнь лота (DEC-016).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_pv_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('binary_match_id')->constrained('v2_binary_matches');
            $table->foreignId('pv_lot_id')->constrained('v2_pv_lots');
            $table->string('side', 5);
            $table->decimal('pv_consumed', 18, 6);
            $table->unsignedBigInteger('bv_usd_cents_consumed');
            $table->timestamp('created_at');

            $table->unique(['binary_match_id', 'pv_lot_id'], 'v2_pv_lot_alloc_match_lot_uq');
            $table->index('pv_lot_id', 'v2_pv_lot_alloc_lot_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_pv_lot_allocations');
    }
};
