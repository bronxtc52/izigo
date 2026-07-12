<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T06 (mh-full-plan): структурная (бинарная) премия 5-9% от matched BV с
 * полумесячными/месячными капами. Одна строка на (period_id half-month, member_id).
 *
 * Каскад DEC-053: gross → индивидуальные капы (after_cap_cents) → 60%-пул
 * (pool_* заполняет T11, поля зарезервированы) → net_cents (= after_cap_cents до
 * T11; база лидерского T08 по DEC-029). Начисление на НС (LedgerV2::credit с
 * accrual_month) при закрытии half-month; перевод НС→ОС после месячной калибровки —
 * зона T04 (amendments MF-4/MF-6), не T06.
 *
 * Решение владельца (Гейт A / amendments): сматченный PV сверх денежного капа
 * СГОРАЕТ — forfeited_cents = gross_cents − after_cap_cents (дельта видна).
 *
 * match_group_id + posting_idempotency_key + status — контракт reversal-готовности
 * для T12. explanation (DEC-054) — входные allocations/ранг/ставка/шаги cap/версия
 * политики для отчёта T11 и админ-breakdown. Слот миграции 2026_07_13_11xxxx
 * (docs/mh-full-plan-migration-ledger.md). FK на v2_calc_periods мягкий (по id,
 * без constrained) — порядок миграций между задачами волны не фиксируется жёстко.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_structure_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id'); // FK v2_calc_periods (T04), мягкая ссылка
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedBigInteger('policy_version_id')->nullable(); // provenance (T01)
            $table->string('rank_code', 32); // снапшот достигнутого ранга на конец окна (DEC-017/020)
            $table->unsignedInteger('rate_bps'); // ставка статуса 500..900
            $table->decimal('matched_pv', 18, 6)->default('0'); // amendments #3: PV decimal(18,6)
            $table->unsignedBigInteger('matched_bv_cents')->default(0); // BV потреблённых лотов (DEC-016, из T03)
            $table->unsignedBigInteger('match_group_id')->nullable(); // ссылка на v2_binary_matches (reversals T12)

            $table->unsignedBigInteger('gross_cents')->default(0); // до индивидуальных капов
            $table->unsignedBigInteger('half_cap_cents')->default(0); // кап окна (снапшот)
            $table->unsignedBigInteger('monthly_cap_cents')->default(0); // месячный кап (снапшот)
            $table->unsignedBigInteger('cap_remaining_before_cents')->default(0); // месячный остаток ДО этого окна
            $table->unsignedBigInteger('after_cap_cents')->default(0); // после индивидуальных капов (вход T11)
            $table->unsignedBigInteger('forfeited_cents')->default(0); // сгоревшее сверх капа (gross − after_cap)

            // Резерв под 60%-калибровку T11 (не мигрировать чужую таблицу):
            $table->decimal('pool_coefficient', 12, 8)->nullable();
            $table->unsignedBigInteger('pool_adjustment_cents')->nullable();

            $table->unsignedBigInteger('net_cents')->default(0); // сумма posting (= after_cap до T11); база лидерского T08
            $table->string('accrual_month', 7); // 'YYYY-MM' атрибуция начисления НС (перевод НС→ОС, MF-3)
            $table->string('status', 12)->default('calculated'); // calculated|posted|reversed
            $table->string('posting_idempotency_key')->nullable()->unique(); // v2:structure:{period_id}:{member_id}
            $table->jsonb('explanation')->nullable(); // DEC-054
            $table->timestamps();

            $table->unique(['period_id', 'member_id'], 'v2_structure_bonuses_period_member_uq');
            $table->index(['period_id', 'status'], 'v2_structure_bonuses_period_status_ix');
            $table->index(['member_id', 'accrual_month'], 'v2_structure_bonuses_member_month_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE v2_structure_bonuses ADD CONSTRAINT v2_structure_bonuses_status_ck '
                . "CHECK (status IN ('calculated', 'posted', 'reversed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_structure_bonuses');
    }
};
