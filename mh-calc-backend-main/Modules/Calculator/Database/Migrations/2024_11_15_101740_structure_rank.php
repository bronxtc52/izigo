<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Calculator\Models\Structure\Structure;
use Modules\Calculator\Services\CalculatorService;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calculator_structures', function (Blueprint $table) {
            $table->integer('root_last_rank')->default(0);
        });

        $structuresList = Structure::get();
        foreach ($structuresList as $structure)
        {
            $calculator = new CalculatorService(config('app.currency_code'), $structure);
            $calculator->calculate();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calculator_structures', function (Blueprint $table) {
            $table->dropColumn('root_last_rank');
        });
    }
};
