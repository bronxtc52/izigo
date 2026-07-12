<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T09 (mh-full-plan): аллокации пула по участникам + строки UNALLOCATED (компания).
 * member_id NULL = kind=unallocated (остаток компании, DEC-034; ledger-проводки нет —
 * деньги компанию не покидали). raw_cents (largest-remainder, DEC-035) → capped_cents
 * (после кап 25%, DEC-034) → final_cents (default=capped; T11 60%-калибровка
 * перезаписывает final_cents ДО финализации месяца — единственная точка интеграции).
 * Квартальная выплата суммирует final_cents.
 *
 * Инвариант пула: Σ capped_cents(member) + Σ capped_cents(unallocated) == pool_amount_cents.
 *
 * partial unique (pool_id) where member_id is null — ровно одна UNALLOCATED-строка на
 * пул (amendments nice-to-have #7); unique(pool_id, member_id) — дедуп member-строк.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_global_bonus_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_bonus_month_id')->constrained('v2_global_bonus_months')->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained('v2_global_bonus_pools')->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members');
            $table->string('kind', 16); // member | unallocated
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedBigInteger('raw_cents')->default(0);
            $table->unsignedBigInteger('capped_cents')->default(0);
            $table->unsignedBigInteger('final_cents')->default(0);
            $table->string('status', 16)->default('accrued'); // accrued | paid | reversed
            $table->timestamps();

            $table->unique(['pool_id', 'member_id'], 'v2_glb_alloc_pool_member_uq');
            $table->index(['global_bonus_month_id', 'member_id'], 'v2_glb_alloc_month_member_ix');
        });

        // Ровно одна UNALLOCATED-строка на пул (member_id IS NULL): partial unique index
        // (amendments nice-to-have #7 — unique(pool_id, member_id) NULL-строки не ловит).
        DB::statement(
            'CREATE UNIQUE INDEX v2_glb_alloc_unalloc_uq ON v2_global_bonus_allocations (pool_id) WHERE member_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_global_bonus_allocations');
    }
};
