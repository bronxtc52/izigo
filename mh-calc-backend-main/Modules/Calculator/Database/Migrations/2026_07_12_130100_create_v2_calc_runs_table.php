<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T04: расчётные прогоны периодов (v2_calc_runs). idempotency_key
 * UNIQUE (напр. 'close:half_month:2026-07-H1') — один боевой close-прогон на период;
 * result_hash — sha256 детерминированного результата (ARCH-NFR-01).
 *
 * snapshot_id — обратная ссылка на v2_calc_snapshots без FK: снапшот сам ссылается
 * на run (run_id FK UNIQUE в следующей миграции слота), двусторонний constraint
 * дал бы циклическую зависимость таблиц.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_calc_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedInteger('run_no');
            $table->string('mode', 12);   // preview|close (rerun добавит T12)
            $table->string('status', 12); // pending|running|succeeded|failed|superseded
            $table->timestamp('input_cutoff');
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->string('engine_version', 32);
            $table->string('result_hash', 64)->nullable();
            $table->string('idempotency_key')->unique();
            $table->json('step_results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'run_no']);
            $table->foreign('period_id')->references('id')->on('v2_calc_periods');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_calc_runs');
    }
};
