<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T09 (mh-full-plan): квалификации участников (rank>=Director) месяца.
 * shares = min(floor(referral_tree_pv / base_pv), max_shares); строка создаётся и
 * при shares=0 (участник достиг ранга, но PV месяца ниже порога — контракт для
 * отчёта и T10). PV — decimal(18,6) (amendments nice-to-have #3).
 *
 * КОНТРАКТ для T10 (DEC-042): VP-этапы наград 2-3 = первые две записи с shares>=1
 * при achieved_rank=VICE_PRESIDENT — схему после старта T10 не менять.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_global_bonus_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_bonus_month_id')->constrained('v2_global_bonus_months')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members');
            $table->string('achieved_rank', 32);
            $table->decimal('referral_tree_pv', 18, 6)->default(0);
            $table->decimal('base_pv', 18, 6)->default(0);
            $table->unsignedInteger('max_shares');
            $table->unsignedInteger('shares')->default(0);
            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['global_bonus_month_id', 'member_id'], 'v2_glb_quals_month_member_uq');
            $table->index(['achieved_rank', 'shares'], 'v2_glb_quals_rank_shares_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_global_bonus_qualifications');
    }
};
