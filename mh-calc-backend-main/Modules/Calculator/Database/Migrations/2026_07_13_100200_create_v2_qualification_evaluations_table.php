<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T05 (mh-full-plan): полный снапшот оценки квалификации статуса (BR-RANK-002,
 * спека 05 §4.6): целевой ранг, использованный вариант (null = fail), список
 * квалифаеров с их корневыми ветвями и рангами на evaluated_at, per-criterion
 * разбор и evidence_hash. Append-only: повторная оценка пишет НОВУЮ строку;
 * защита от дублей рангов — unique в v2_rank_history, не здесь.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_qualification_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('member_id')->constrained('members');
            $table->string('target_rank_code', 32);
            $table->timestamp('as_of');
            $table->unsignedBigInteger('policy_version_id');
            $table->decimal('small_branch_pv', 18, 6);
            $table->string('variant_used', 8)->nullable(); // V1|V2|V3, null = fail/без вариантов
            $table->boolean('passed');
            // [{qualifier_partner_id, root_branch_member_id, rank_code_as_of, slot: anchor|support}]
            $table->jsonb('qualifiers_json')->nullable();
            // per-criterion: [{rule_id, actual, required, passed, reason}]
            $table->jsonb('criteria_json')->nullable();
            $table->string('evidence_hash', 64);
            $table->string('trigger', 16); // order|grace|manual|migration
            $table->timestamp('created_at');

            $table->index(['member_id', 'created_at'], 'v2_qual_evals_member_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_qualification_evaluations');
    }
};
