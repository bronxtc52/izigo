<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T04: журнал исполнений scheduled-джобов V2 по окнам
 * (v2_calc_job_executions). UNIQUE(job_name, window_key) — ядро идемпотентности
 * (DEC-019): повтор succeeded-окна = no-op; конкурентная вставка того же окна
 * ловит unique violation и корректно выходит; failed-окно переисполняется
 * тем же рядом (attempts+1).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_calc_job_executions', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 64);   // 'half-month-close'|'month-close'|'ns-os-transfer'|'quarter-payout'
            $table->string('window_key', 40); // '2026-07-H1' | '2026-07' | 'ns-os:2026-07' | '2026-Q3'
            $table->string('status', 12);     // running|succeeded|failed
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['job_name', 'window_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_calc_job_executions');
    }
};
