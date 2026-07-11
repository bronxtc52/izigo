<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T05 (mh-full-plan): монотонная история достигнутых рангов — «ранг навсегда»
 * (DEC-020, решение владельца). unique(member_id, rank_code) — одновременно
 * guard монотонности и идемпотентный триггер наград T10 (контракт волны).
 * При скачке через ранги пишется ОТДЕЛЬНАЯ строка на КАЖДЫЙ пройденный ранг
 * с одним evaluation_id (DEC-040 — «все пройденные награды»).
 * rank_ordinal — для сравнений «минус два статуса» в T08 (DEC-030).
 * evaluation_id nullable: CLIENT/CONSULTANT приходят из жизненного цикла
 * (ClientLifecycleService), а не из RankEvaluator.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_rank_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->string('rank_code', 32);
            $table->unsignedInteger('rank_ordinal');
            $table->timestamp('achieved_at');
            $table->foreignUuid('evaluation_id')->nullable()
                ->constrained('v2_qualification_evaluations');
            $table->unsignedBigInteger('policy_version_id');
            $table->timestamp('created_at');

            $table->unique(['member_id', 'rank_code'], 'v2_rank_history_member_rank_uq');
            $table->index(['member_id', 'achieved_at'], 'v2_rank_history_asof_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_rank_history');
    }
};
