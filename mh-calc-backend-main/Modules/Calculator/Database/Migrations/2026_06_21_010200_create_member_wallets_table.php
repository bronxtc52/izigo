<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Денормализованный кэш баланса кошелька (Фаза 3). Source of truth — ledger_entries;
 * этот кэш = свёртка журнала по типам счетов, обновляется в той же транзакции.
 * Суммы — целые центы. Инвариант (тест): available = Σcredit−Σdebit member_available и т.д.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->unsignedBigInteger('available_cents')->default(0); // доступно к выводу
            $table->unsignedBigInteger('held_cents')->default(0);      // в холде под заявку
            $table->unsignedBigInteger('clawback_debt_cents')->default(0); // долг при отрицательной коррекции
            $table->string('currency', 8)->default('USD');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_wallets');
    }
};
