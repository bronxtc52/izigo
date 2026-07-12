<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T12: журнал шагов reversal-chain (v2_reversal_actions) = explainability возврата.
 * Одна строка на конкретный эффект: реверс PV-лота, компенсация матча, обратная
 * бонус-проводка, clawback-долг, корректировка базы тира, note о неотзываемой
 * квалификации/награде, предложение корректировки закрытого периода.
 *
 * amount_cents SIGNED (int, отрицательный = сторно начисления). snapshot_json —
 * ОРИГИНАЛЬНЫЕ rate/tier/rank/scale на момент исходной проводки: reversal считается
 * ТОЛЬКО из снапшота, НИКОГДА по текущим tier/rank (CAL-REV-001, тест-план T12).
 * ledger_entries (V1-таблица) НЕ альтерим — только ссылка ledger_tx_id обеих сторон.
 * idempotency_key уникален — повтор шага = no-op (alreadyPosted паттерн).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_reversal_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('v2_order_returns')->cascadeOnDelete();
            // pv_lot_reversal|match_compensation|bonus_reversal|clawback|tier_basis_adjust|qualification_note|period_correction_proposed
            $table->string('action_type', 32);
            $table->string('bonus_type', 16)->nullable(); // structural|referral|leadership|global
            $table->string('target_type', 32)->nullable(); // pv_lot|binary_match|structure_bonus|referral_reward|leadership_line|global_payout
            $table->unsignedBigInteger('target_id')->nullable();
            $table->bigInteger('amount_cents')->default(0);   // SIGNED (сторно = отрицательное)
            $table->decimal('amount_pv', 18, 6)->default(0);
            $table->json('snapshot_json')->nullable();        // original rate/tier/rank/scale (CAL-REV-001)
            $table->string('ledger_tx_id')->nullable();       // ссылка на tx-группу reversal-проводки
            $table->string('status', 16)->default('pending'); // pending|posted|skipped
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['return_id', 'action_type'], 'v2_reversal_actions_return_type_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_reversal_actions ADD CONSTRAINT v2_reversal_actions_action_ck "
                . "CHECK (action_type IN ('pv_lot_reversal','match_compensation','bonus_reversal',"
                . "'clawback','tier_basis_adjust','qualification_note','period_correction_proposed'))"
            );
            DB::statement(
                "ALTER TABLE v2_reversal_actions ADD CONSTRAINT v2_reversal_actions_status_ck "
                . "CHECK (status IN ('pending','posted','skipped'))"
            );
            DB::statement(
                "ALTER TABLE v2_reversal_actions ADD CONSTRAINT v2_reversal_actions_bonus_ck "
                . "CHECK (bonus_type IS NULL OR bonus_type IN ('structural','referral','leadership','global'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_reversal_actions');
    }
};
