<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calculator_rank_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calculator_rank_id')->constrained()->onDelete('cascade');
            $table->string('locale', 5)->index();
            $table->float('rank_bonus_amount', 20, 2)->default(0);
            $table->unique(['calculator_rank_id', 'locale']);
        });

        foreach ([
                     1 => ['kk' => 0, 'mn' => 0, 'ru' => 0, 'uz' => 0, 'ky' => 0, 'az' => 0],
                     2 => ['kk' => 46800, 'mn' => 347600, 'ru' => 8500, 'uz' => 1150000, 'ky' => 9000, 'az' => 170],
                     3 => ['kk' => 93600, 'mn' => 695200, 'ru' => 17000, 'uz' => 2300000, 'ky' => 18000, 'az' => 340],
                     4 => ['kk' => 140400, 'mn' => 1042800, 'ru' => 25500, 'uz' => 3450000, 'ky' => 27000, 'az' => 510],
                 ] as $rankSort => $localesList) {
            foreach ($localesList as $locale => $amount) {
                DB::table('calculator_rank_bonuses')->insert([
                    'calculator_rank_id' => DB::raw("(SELECT id FROM calculator_ranks WHERE sort = {$rankSort})"),
                    'locale' => $locale,
                    'rank_bonus_amount' => $amount
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calculator_rank_bonuses');
    }
};
