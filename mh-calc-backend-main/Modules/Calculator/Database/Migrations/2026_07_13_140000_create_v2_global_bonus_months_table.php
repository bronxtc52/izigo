<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T09 (mh-full-plan): immutable-снапшот месяца глобального бонуса (DEC-036).
 * Одна строка на месячный период (unique). global_bv_cents — Σ BV PAID-заказов
 * месяца минус reversed (integer USD-центы). status draft→final: финальный месяц
 * пересчёту не подлежит (только корректирующие проводки T12). Слот миграции —
 * docs/mh-full-plan-migration-ledger.md (T09 = 2026_07_13_14xxxx).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_global_bonus_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('month_period_id')->unique()->constrained('v2_calc_periods');
            $table->unsignedBigInteger('policy_version_id');
            $table->unsignedBigInteger('global_bv_cents')->default(0);
            $table->string('status', 16)->default('draft'); // draft | final
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('status', 'v2_glb_months_status_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_global_bonus_months');
    }
};
