<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Иммутабельный журнал двойной записи (Фаза 3) — источник истины для денег.
 * Каждая операция = группа проводок с одним tx_id, где Σ(debit) = Σ(credit).
 * Суммы — целые центы (bigInteger), без float. member_id = NULL для счетов компании.
 * Append-only: историю не правим, только компенсирующими проводками.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('tx_id'); // группа сбалансированных проводок одной операции
            $table->foreignId('member_id')->nullable()
                ->constrained('members')->cascadeOnDelete();
            // company_commission_expense|member_available|member_held|company_payouts_paid|member_clawback_debt
            $table->string('account_type', 32);
            $table->string('direction', 6); // debit|credit
            $table->unsignedBigInteger('amount_cents'); // всегда > 0; сторона задаётся direction
            $table->string('source_type', 16); // accrual|withdrawal|adjustment
            $table->unsignedBigInteger('source_id')->nullable(); // activation_event_id | withdrawal_request_id
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('tx_id');
            $table->index(['member_id', 'account_type']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
