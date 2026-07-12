<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T15 (W6): append-only аудит-журнал действий cutover V1→V2.
 * Схема-миграция additive, forward-only; ДАННЫЕ прода она НЕ мигрирует —
 * перенос балансов делает идемпотентная artisan-команда calc-v2:cutover-migrate
 * (НЕ авто на деплое). Слот 2026_07_14_20xxxx (docs/mh-full-plan-migration-ledger.md,
 * волна W6). Таблицы V1 (ledger_entries/member_wallets) НЕ альтерятся.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_cutover_log', function (Blueprint $table) {
            $table->id();
            // Тип действия: bronze_tariff | opening_migration | reconciliation | parity | phase.
            $table->string('action', 32);
            // Фаза cutover в момент записи: pre | dry_run | migrated | rolled_back.
            $table->string('phase', 16)->nullable();
            // Кто запустил команду (CLI actor / owner login), свободный текст.
            $table->string('actor')->nullable();
            // dry-run по умолчанию: строка dry_run=true — только план, без денежных проводок.
            $table->boolean('dry_run')->default(true);
            $table->unsignedBigInteger('member_id')->nullable();
            $table->bigInteger('amount_cents')->nullable();
            // tx_id reclass-группы в ledger_entries (opening_migration) — трассировка.
            $table->uuid('tx_id')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('member_id');
            $table->index('created_at');
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_cutover_log');
    }
};
