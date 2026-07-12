<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T09 (mh-full-plan): статусные пулы месяца (Director..VP). pool_amount_cents =
 * intdiv(global_bv_cents * rate_bps, 10000) — integer USD-центы. allocated_cents +
 * unallocated_cents == pool_amount_cents (инвариант двойной записи снапшота).
 * unallocated_reason: cap_remainder (кап 25% срезал), empty_pool (нет долей),
 * rounding — код причины для отчёта админу (DEC-034).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_global_bonus_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_bonus_month_id')->constrained('v2_global_bonus_months')->cascadeOnDelete();
            $table->string('pool_rank', 16); // director | pearl | sapphire | diamond | vp
            $table->unsignedInteger('rate_bps');
            $table->unsignedBigInteger('pool_amount_cents')->default(0);
            $table->unsignedBigInteger('total_shares')->default(0);
            $table->unsignedBigInteger('allocated_cents')->default(0);
            $table->unsignedBigInteger('unallocated_cents')->default(0);
            $table->string('unallocated_reason', 16)->nullable(); // cap_remainder | empty_pool | rounding
            $table->timestamps();

            $table->unique(['global_bonus_month_id', 'pool_rank'], 'v2_glb_pools_month_rank_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_global_bonus_pools');
    }
};
