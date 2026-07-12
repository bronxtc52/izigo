<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T15 (W6): per-member/per-check расхождения паритетного прогона.
 * classification (task-словарь): match (обязано совпасть и совпало) |
 * mismatch (обязано совпасть, но НЕ совпало — реальная проблема) |
 * v2_only (механика V2 без аналога в V1) | plan_change (V1-величина по решению
 * владельца поглощается opening-балансом, расхождение by-design, не блокирует).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_parity_diffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('v2_parity_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('member_id');
            // Имя проверки: money_conservation | accrued_income | tree_composition | ...
            $table->string('check', 32);
            $table->bigInteger('v1_amount_cents')->default(0);
            $table->bigInteger('v2_amount_cents')->default(0);
            $table->bigInteger('delta_cents')->default(0);
            $table->string('classification', 24);
            $table->text('note')->nullable();

            $table->index(['run_id', 'member_id']);
            $table->index(['run_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_parity_diffs');
    }
};
