<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T11 (mh-full-plan): 60%-калибровка выплат — заголовок расчёта на МЕСЯЦ
 * (v2_pool_calibrations, слот W4 = 2026_07_13_17xxxx, amendments MF-8 префикс v2_).
 *
 * ЕДИНСТВЕННЫЙ владелец формулы (amendments MF-1/2, целочисленная математика):
 *   pool_cap_cents = intdiv(base_bv_cents * rate_bps, 10000)
 *   factor_bps     = total_after_caps_cents == 0 ? 10000
 *                    : min(10000, intdiv(pool_cap_cents * 10000, total_after_caps_cents))
 * Числитель total_after_caps_cents = структурная-после-капов + месячное накопление
 * глобального. Реферальная (referral_gross_cents) — информационно, В ЧИСЛИТЕЛЬ НЕ ВХОДИТ
 * (решение владельца MF-W3-3); лидерский считается ПОСЛЕ от post-calibration базы (DEC-029);
 * награды исключены (Гейт A). Только scale-down (factor ≤ 10000).
 *
 * КОНТРАКТ T11→T08/T04: factor_bps закоммиченной строки читают
 * PoolCalibrationReader (T08 лидерский) и NsToOsTransfer (T04 перевод НС→ОС). Ровно
 * одна committed-строка на месяц (partial unique index), BR-POOL-002 — прежние версии
 * не перезаписываются, а помечаются superseded.
 *
 * Деньги — integer USD-центы; дельта (raw − paid) удерживается компанией на
 * company_pool_retained (структурная — двойной записью в T02 NsToOsTransfer по
 * закоммиченному factor_bps; глобальная — неаллоцированным остатком, final_cents).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_pool_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('v2_calc_periods')->cascadeOnDelete();
            // Денормализация месяца 'YYYY-MM' — быстрый lookup контракта reader'ом.
            $table->string('month', 7);
            $table->unsignedInteger('run_version')->default(1);
            $table->unsignedBigInteger('policy_version_id')->nullable();

            $table->unsignedInteger('pool_rate_bps');           // 6000 (60%)
            $table->unsignedBigInteger('base_bv_cents');        // BV-оборот месяца
            $table->unsignedBigInteger('pool_cap_cents');       // 60% base_bv

            $table->unsignedBigInteger('structure_after_caps_cents')->default(0);
            $table->unsignedBigInteger('global_after_caps_cents')->default(0);
            $table->unsignedBigInteger('referral_gross_cents')->default(0); // информационно, вне числителя
            $table->unsignedBigInteger('total_after_caps_cents');           // числитель = structure + global

            $table->unsignedInteger('factor_bps');              // 0..10000 — КОНТРАКТ T08/T04
            $table->unsignedBigInteger('scaled_total_cents');   // Σ выплат после factor
            $table->unsignedBigInteger('company_retained_cents'); // total − scaled

            $table->string('status', 16)->default('committed'); // draft|committed|superseded
            $table->string('created_by')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'run_version'], 'v2_pool_cal_period_ver_uq');
            $table->index('month', 'v2_pool_cal_month_ix');
        });

        // Ровно одна committed-калибровка на месяц (контракт reader'а T08/T04, BR-POOL-002).
        DB::statement(
            "CREATE UNIQUE INDEX v2_pool_cal_committed_uq ON v2_pool_calibrations (period_id) WHERE status = 'committed'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_pool_calibrations');
    }
};
