<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T12: очередь корректирующих проводок закрытых периодов (v2_period_corrections).
 * DEC-027 / план §367: закрытый период НЕ переоткрывается — эффект возврата,
 * попавший в уже закрытый (и, возможно, откалиброванный) период, оформляется
 * ОТДЕЛЬНОЙ корректирующей проводкой, минуя assertOpen-guard T04. Исходные
 * run/строки закрытия не редактируются.
 *
 * status: proposed → approved → posted (идемпотентная проводка) | rejected.
 * amount_cents SIGNED. approve/reject — один owner (dec-triage: без four-eyes,
 * обязателен reason + audit). Контракт-чек W2+ №5: корректировки НЕ кредитуют НС
 * уже переведёнными (откалиброванными) месяцами — только текущим месяцем или
 * напрямую ОС с пометкой (см. PeriodCorrectionService).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_period_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('v2_calc_periods');
            $table->foreignId('return_id')->nullable()->constrained('v2_order_returns')->nullOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->string('bonus_type', 16);                 // structural|referral|leadership|global
            $table->bigInteger('amount_cents');               // SIGNED (сторно = отрицательное)
            $table->string('status', 16)->default('proposed'); // proposed|approved|posted|rejected
            $table->text('reason');
            $table->json('snapshot_json')->nullable();
            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('ledger_tx_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['period_id', 'status'], 'v2_period_corrections_period_status_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_period_corrections ADD CONSTRAINT v2_period_corrections_status_ck "
                . "CHECK (status IN ('proposed','approved','posted','rejected'))"
            );
            DB::statement(
                "ALTER TABLE v2_period_corrections ADD CONSTRAINT v2_period_corrections_bonus_ck "
                . "CHECK (bonus_type IN ('structural','referral','leadership','global'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_period_corrections');
    }
};
