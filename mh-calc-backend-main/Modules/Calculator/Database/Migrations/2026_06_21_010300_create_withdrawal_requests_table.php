<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заявки на вывод средств (Фаза 3). Жизненный цикл:
 * requested → approved → paid, либо requested → rejected, либо approved → cancelled.
 * Выплата ручная (вне системы); реальные платёжные рельсы — Фаза 4.
 * Сумма — целые центы. Холд/возврат/выплата отражаются проводками в ledger_entries.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->text('payout_details'); // реквизиты текстом (банк/крипто)
            $table->string('status', 16)->default('requested'); // requested|approved|paid|rejected|cancelled
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->string('idempotency_key')->nullable()->unique();

            $table->index(['member_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
