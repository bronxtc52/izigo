<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T04: расчётные периоды V2 (словарь MF-8: v2_calc_periods).
 * Полуоткрытые интервалы [starts_at, ends_at) в UTC: оплата ровно 16-го 00:00 → H2,
 * ровно 1-го 00:00 → новый месяц. Статусы open → closing → closed; reopen нет.
 *
 * policy_version_id — логическая ссылка на v2_policy_versions (T01, параллельная
 * задача волны): FK-констрейнт НЕ ставим — T01 мержится независимо, а constraint
 * на таблицу параллельной ветки сломал бы миграции до merge train. Целостность
 * держит PeriodService (резолв через PolicyVersionResolver на starts_at).
 * Слот миграций T04 — 2026_07_12_13xxxx (docs/mh-full-plan-migration-ledger.md).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_calc_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period_type', 16); // half_month|month|quarter
            $table->string('code', 20);        // '2026-07-H1' | '2026-07' | '2026-Q3'
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 40)->default('UTC');
            $table->string('status', 12)->default('open'); // open|closing|closed
            $table->unsignedBigInteger('policy_version_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 64)->nullable(); // 'system' | admin member id
            $table->timestamps();

            $table->unique(['period_type', 'starts_at']);
            $table->unique(['period_type', 'code']);
            $table->index('status');
            $table->index('policy_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_calc_periods');
    }
};
