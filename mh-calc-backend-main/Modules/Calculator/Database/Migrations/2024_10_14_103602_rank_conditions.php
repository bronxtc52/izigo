<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Calculator\Models\Rank;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calculator_ranks', function (Blueprint $table) {
            $table->float('binary_small_branch_volume')->default(0)->comment('Объем малой ветки бинара');
            $table->integer('personal_count')->default(0)->comment('personal_count лично приглашенных');
            $table->integer('personal_in_rank_count')->default(0)->comment('personal_in_rank_count лично приглашенных в ранге personal_in_rank_id');
            $table->integer('personal_in_rank_id')->default(0)->comment('personal_in_rank_count лично приглашенных в ранге personal_in_rank_id');
        });

        $managerRankId = Rank::where('alias', 'manager')->value('id');
        DB::unprepared("UPDATE calculator_ranks SET binary_small_branch_volume = 100, personal_count = 1  WHERE sort = 1;");
        DB::unprepared("UPDATE calculator_ranks SET binary_small_branch_volume = 1000, personal_count = 4  WHERE sort = 2;");
        DB::unprepared("UPDATE calculator_ranks SET binary_small_branch_volume = 3000, personal_count = 8  WHERE sort = 3;");
        DB::unprepared("UPDATE calculator_ranks SET binary_small_branch_volume = 8000, personal_in_rank_count = 3,  personal_in_rank_id = {$managerRankId}  WHERE sort = 4;");

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calculator_ranks', function (Blueprint $table) {
            $table->dropColumn('binary_small_branch_volume');
            $table->dropColumn('personal_count');
            $table->dropColumn('personal_in_rank_count');
            $table->dropColumn('personal_in_rank_id');
        });
    }
};
