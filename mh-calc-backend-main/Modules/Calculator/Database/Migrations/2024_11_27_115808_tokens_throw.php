<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('calculator_user_tokens')->update(['expires_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
