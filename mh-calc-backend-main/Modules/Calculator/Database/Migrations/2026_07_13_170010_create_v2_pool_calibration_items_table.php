<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T11 (mh-full-plan): построчный drill-down 60%-калибровки (v2_pool_calibration_items).
 * По видам бонусов, входящих в числитель и калибруемых factor_bps:
 *
 *  - structure: одна строка на участника (агрегат обеих половин месяца по accrual_month);
 *    calibrated = intdiv(after_caps × factor, 10000) — floor ПОУЧАСТНИКА, зеркалит T02
 *    NsToOsTransfer (перевод НС→ОС берёт per-member НС-баланс). source_ref = member_id.
 *  - global: одна строка на аллокацию (member может иметь строки в нескольких пулах при
 *    наследовании); calibrated распределяется largest-remainder так, что Σ = intdiv(
 *    Σcapped × factor, 10000); calibrated пишется в final_cents аллокации. source_ref = allocation_id.
 *
 * Реферальная В ЭТУ ТАБЛИЦУ НЕ ПИШЕТСЯ (вне 60%-пула, MF-W3-3 — в отчёте отдельной строкой
 * без factor, считается на лету из v2_referral_rewards). Деньги — integer USD-центы.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_pool_calibration_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_id')->constrained('v2_pool_calibrations')->cascadeOnDelete();
            $table->string('bonus_kind', 16); // structure | global
            $table->foreignId('member_id')->nullable()->constrained('members');
            // structure: member_id-агрегат; global: id аллокации v2_global_bonus_allocations.
            $table->unsignedBigInteger('source_ref')->nullable();
            $table->unsignedBigInteger('amount_after_caps_cents');
            $table->unsignedBigInteger('calibrated_cents');
            $table->unsignedBigInteger('retained_cents')->default(0);
            // projected — фактическую проводку делает T02 (structure); applied — final_cents уже записан (global).
            $table->string('state', 16)->default('applied');
            $table->timestamps();

            $table->unique(['calibration_id', 'bonus_kind', 'source_ref'], 'v2_pool_cal_item_uq');
            $table->index(['calibration_id', 'member_id'], 'v2_pool_cal_item_member_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_pool_calibration_items');
    }
};
