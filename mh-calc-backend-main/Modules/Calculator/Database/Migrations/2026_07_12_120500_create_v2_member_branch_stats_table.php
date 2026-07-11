<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T03 (mh-full-plan): пересоздаваемая проекция ролей веток бинара по лотам.
 * Источник истины — v2_pv_lots; recompute() детерминированно восстанавливает
 * строку с нуля после каждого инжеста/матчинга/reversal. large_side — по
 * lifetime PV (равенство => NULL, tie). small_branch_lifetime_pv — контракт
 * порогов «малой ветки» для T05.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_member_branch_stats', function (Blueprint $table) {
            $table->foreignId('member_id')->primary()->constrained('members');
            $table->decimal('left_free_pv', 18, 6)->default(0);
            $table->decimal('right_free_pv', 18, 6)->default(0);
            $table->decimal('left_lifetime_pv', 18, 6)->default(0);
            $table->decimal('right_lifetime_pv', 18, 6)->default(0);
            $table->string('large_side', 5)->nullable(); // left|right|NULL (tie)
            $table->decimal('small_branch_lifetime_pv', 18, 6)->default(0);
            $table->timestamp('recomputed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_member_branch_stats');
    }
};
