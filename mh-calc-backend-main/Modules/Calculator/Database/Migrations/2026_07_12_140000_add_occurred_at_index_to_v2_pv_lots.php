<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NTH-2 ревью W1: runMatchingForPeriod фильтрует v2_pv_lots по occurred_at < cutoff
 * без владельца — существующий композитный индекс начинается с owner_member_id
 * (миграция 120200) и не помогает, на объёме это полный скан.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_pv_lots', function (Blueprint $table) {
            $table->index('occurred_at', 'v2_pv_lots_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('v2_pv_lots', function (Blueprint $table) {
            $table->dropIndex('v2_pv_lots_occurred_at_index');
        });
    }
};
