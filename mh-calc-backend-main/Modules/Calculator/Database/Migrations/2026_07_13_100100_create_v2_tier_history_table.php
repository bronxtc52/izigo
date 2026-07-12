<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T05 (mh-full-plan): append-only история тиров контракта START/BUSINESS/ELITE
 * (BR-TIER-001, CAL-TIER-001). Тир НЕ понижается (DEC-020/DEC-027: реверсы
 * уменьшают PV-базу, но тир/ранг не отзываются) — unique(member_id, tier) даёт
 * идемпотентность повышения. tierAsOf для T07 читается ОТСЮДА (as-of контракт),
 * а не из v2_partner_states.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_tier_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->string('tier', 16);          // достигнутый тир
            $table->string('tier_before', 16)->nullable();
            $table->decimal('basis_personal_pv', 18, 6); // накопленный personal PV на момент
            $table->foreignId('source_order_id')->nullable()->constrained('orders');
            $table->unsignedBigInteger('policy_version_id');
            $table->timestamp('effective_at');   // момент оплаты заказа-триггера
            $table->timestamp('created_at');

            $table->unique(['member_id', 'tier'], 'v2_tier_history_member_tier_uq');
            $table->index(['member_id', 'effective_at'], 'v2_tier_history_asof_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_tier_history ADD CONSTRAINT v2_tier_history_tier_ck "
                . "CHECK (tier IN ('START', 'BUSINESS', 'ELITE'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_tier_history');
    }
};
