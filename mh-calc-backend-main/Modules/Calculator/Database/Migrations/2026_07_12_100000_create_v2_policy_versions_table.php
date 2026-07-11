<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T01: версионируемый конфиг политики V2 (словарь схемы — amendments MF-8).
 * Статусы: draft | active | retired (APPROVED нет — one-step owner-activate).
 * Инварианты «максимум одна active (valid_to IS NULL)» и непересечение интервалов
 * [valid_from, valid_to) enforce'ит PolicyVersionService транзакционно под
 * lockForUpdate (DB-констрейнта на интервалы нет). Слот миграций T01 =
 * 2026_07_12_10xxxx (docs/mh-full-plan-migration-ledger.md).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('status', 16)->default('draft'); // draft | active | retired
            $table->smallInteger('schema_version')->default(1);
            $table->timestamp('valid_from')->nullable(); // включительно; ставится при активации
            $table->timestamp('valid_to')->nullable();   // исключительно; NULL = текущая
            $table->json('config');                      // полный документ плана (деньги — int USD-центы)
            $table->string('config_hash', 64);           // sha256 канонического JSON — в снапшоты T04/T06-T11
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('activated_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_policy_versions');
    }
};
