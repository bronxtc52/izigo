<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Снимок начислений текущего состояния сети (для дашборда кабинета). Деньги — decimal.
 * basis хранит контекст расчёта (тип/уровень/источник/мета) для строки «логика расчёта».
 * Без ledger двойной записи (Фаза 3) — это денормализованный снимок последнего пересчёта.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_bonus_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_member_id')->constrained('members')->cascadeOnDelete();
            $table->string('type', 16); // binary|referral|leader|rank
            $table->decimal('amount', 20, 2)->default(0);
            $table->json('basis')->nullable();
            $table->foreignId('source_event_id')->nullable()
                ->constrained('activation_events')->nullOnDelete();
            $table->timestamp('calculated_at')->nullable();

            $table->index(['recipient_member_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_bonus_lines');
    }
};
