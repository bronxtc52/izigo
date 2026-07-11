<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): результат матчинга min(free L, free R) участника за прогон.
 * matched_bv_usd_cents — сумма BV фактически потреблённых лотов (DEC-016; легаси
 * коэффициент 421.2 ЗАПРЕЩЁН) — единственный денежный вход T06 (amendments #3).
 * period_key — строка 'YYYY-MM-H1|H2' до появления таблицы периодов (FK добавит
 * T04/T06). Идемпотентность прогона — UNIQUE(member_id, period_key, run_uuid);
 * сервис всегда пишет period_key NOT NULL (nullable в схеме — по плану, для
 * совместимости с будущими диагностическими прогонами).
 *
 * reversal_required_at/reversal_reason — пометка «нужен каскадный reversal» при
 * ручном refund заказа с уже сматченным лотом (историю не переписываем, деньги
 * откатывает T12). status: provisional|final|reversed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_binary_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->string('period_key', 20)->nullable();
            $table->string('run_uuid', 64);
            $table->timestamp('cutoff_at');
            $table->decimal('matched_pv', 18, 6);
            $table->unsignedBigInteger('matched_bv_usd_cents');
            $table->string('status', 12)->default('provisional'); // provisional|final|reversed
            $table->timestamp('reversal_required_at')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'period_key', 'run_uuid'], 'v2_binary_matches_member_run_uq');
            $table->index(['period_key', 'status'], 'v2_binary_matches_period_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_binary_matches ADD CONSTRAINT v2_binary_matches_status_ck "
                . "CHECK (status IN ('provisional', 'final', 'reversed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_binary_matches');
    }
};
