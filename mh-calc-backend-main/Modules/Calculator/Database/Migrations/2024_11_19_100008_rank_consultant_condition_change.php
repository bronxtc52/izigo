<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::unprepared("UPDATE calculator_ranks SET binary_small_branch_volume = 0, personal_count = 1  WHERE sort = 1;");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
