<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T15 (W6): сводка паритетного прогона V1-движок vs V2-модель
 * (calc-v2:parity-check, read-only оракул для owner-гейта). Одна строка = один
 * прогон; per-member/per-check расхождения — в v2_parity_diffs. accepted_* —
 * финансовый sign-off отчёта владельцем перед необратимым флипом.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_parity_runs', function (Blueprint $table) {
            $table->id();
            // pending | running | done | failed
            $table->string('status', 16)->default('done');
            // Диапазон/охват прогона (напр. {members:[...]} или null = вся сеть).
            $table->json('scope')->nullable();
            // Итоги: денежная база V1 (переносимое available) и V2 (проекция opening ОС).
            $table->bigInteger('v1_total_cents')->default(0);
            $table->bigInteger('v2_total_cents')->default(0);
            // Необъяснённая дельта: Σ строк classification=unexplained. accept блокируется, если > 0.
            $table->bigInteger('unexplained_delta_cents')->default(0);
            // Свод по типам проверок/классификациям (для отчёта «одним взглядом»).
            $table->json('summary')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->foreign('accepted_by')->references('id')->on('members')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_parity_runs');
    }
};
