<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T02: денормализованный кэш субсчетов ОС/НС/БС (rebuildable projection
 * поверх ledger_entries). Инвариант: каждая колонка = Σcredit − Σdebit соответствующего
 * account_type V2 (member_os_available / member_os_held / member_ns / member_bs_available /
 * member_bs_held). Источник истины — ledger_entries; таблица пересоздаваема.
 *
 * Словарь схемы v2_* — amendments MF-8. Слот миграций T02 = 2026_07_12_11xxxx
 * (docs/mh-full-plan-migration-ledger.md).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_member_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()
                ->constrained('members')->cascadeOnDelete();
            $table->unsignedBigInteger('os_available_cents')->default(0);
            $table->unsignedBigInteger('os_held_cents')->default(0);
            $table->unsignedBigInteger('ns_cents')->default(0);
            $table->unsignedBigInteger('bs_available_cents')->default(0);
            $table->unsignedBigInteger('bs_held_cents')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_member_accounts');
    }
};
