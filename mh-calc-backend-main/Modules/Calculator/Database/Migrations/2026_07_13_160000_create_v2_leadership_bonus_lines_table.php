<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T08 (mh-full-plan): лидерский бонус глубиной до 7 (CAL-LED-001). Одна таблица —
 * начисления И аудит исключений (status/exclusion_reason). Одна строка на пару
 * (source_structure_bonus_id, receiver_member_id) — ключ идемпотентности.
 *
 * База (DEC-029, amendments MF-1/2): net_cents строки структурной премии T06 ПОСЛЕ
 * индивидуальных капов и 60%-калибровки T11 (T11 пишет калиброванную сумму в net-колонку
 * v2_structure_bonuses ДО шага лидерского — DEC-053 raw→капы→60%-пул→лидерский→posting).
 * T08 сам factor_bps НЕ вычисляет — читает уже калиброванный net (единственная точка стыка
 * с T06/T11, план §630). amount_cents = intdiv(base_cents * rate_bp, 10000), integer USD-центы.
 *
 * DEC-030 (amendments MF-11) «блок без передачи»: узел пути source..receiver (включая
 * source) с ordinal >= receiver_ordinal + rank_gap_block_ordinal_diff (дефолт 3) блокирует
 * себя и всё поддерево — receiver не получает выплату от этого источника, бонус НЕ
 * передаётся выше (exclusion_reason=RANK_GAP_BLOCK, blocking_member_id — виновник).
 * Компрессии нет: depth инкрементится по sponsor-цепочке без сжатия.
 *
 * source_structure_bonus_id + стабильный unique + status=reversed — контракт reversal-
 * готовности для T12 (строки НЕ удаляются). status/exclusion_reason/blocking_member_id —
 * публичный контракт отчёта T13. Слот миграции 2026_07_13_16xxxx (docs/mh-full-plan-
 * migration-ledger.md, Волна W4). Начисление на ОС (кредит-лот 1 год) — не на НС.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_leadership_bonus_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id'); // FK v2_calc_periods (T04) — период ПРОГОНА (month), мягкая ссылка
            $table->foreignId('receiver_member_id')->constrained('members');
            $table->foreignId('source_member_id')->constrained('members');
            $table->unsignedBigInteger('source_structure_bonus_id'); // FK v2_structure_bonuses (T06/T12), мягкая ссылка
            $table->unsignedTinyInteger('depth'); // 1..7 по sponsor-цепочке (БЕЗ компрессии)

            $table->string('receiver_rank_key', 32)->nullable(); // снапшот достигнутого ранга на период (DEC-020)
            $table->string('receiver_tier', 16)->nullable();     // тир получателя на период (START/BUSINESS/ELITE)
            $table->unsignedInteger('rate_bp')->default(0);      // ставка лидерского в basis points
            $table->unsignedBigInteger('base_cents')->default(0); // net структурной премии источника ПОСЛЕ капов+пула
            $table->unsignedBigInteger('amount_cents')->default(0); // integer USD-центы

            $table->string('status', 12)->default('accrued'); // accrued|posted|excluded|reversed
            $table->string('exclusion_reason', 24)->nullable(); // RANK_GAP_BLOCK|BELOW_MANAGER|RATE_ZERO|DEPTH_NOT_ALLOWED
            $table->unsignedBigInteger('blocking_member_id')->nullable(); // виновник rank-gap блока (explainability T13)

            $table->unsignedBigInteger('policy_version_id')->nullable(); // provenance (T01)
            $table->string('ledger_tx_id')->nullable(); // idempotency-ключ проводки ОС (provenance/реверс T12)
            $table->jsonb('explanation')->nullable(); // DEC-054: тир/ранг/ставка/база/depth/blocking
            $table->timestamps();

            $table->unique(['source_structure_bonus_id', 'receiver_member_id'], 'v2_leadership_lines_src_receiver_uq');
            $table->index(['receiver_member_id', 'period_id'], 'v2_leadership_lines_receiver_period_ix');
            $table->index(['period_id', 'status'], 'v2_leadership_lines_period_status_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE v2_leadership_bonus_lines ADD CONSTRAINT v2_leadership_lines_status_ck '
                . "CHECK (status IN ('accrued', 'posted', 'excluded', 'reversed'))"
            );
            DB::statement(
                'ALTER TABLE v2_leadership_bonus_lines ADD CONSTRAINT v2_leadership_lines_reason_ck '
                . "CHECK (exclusion_reason IS NULL OR exclusion_reason IN "
                . "('RANK_GAP_BLOCK', 'BELOW_MANAGER', 'RATE_ZERO', 'DEPTH_NOT_ALLOWED'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_leadership_bonus_lines');
    }
};
