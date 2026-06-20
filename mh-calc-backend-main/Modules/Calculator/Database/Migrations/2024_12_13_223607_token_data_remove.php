<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('calculator_user_tokens', function(Blueprint $table){
            $table->dropColumn('outId');
            $table->dropColumn('lastName');
            $table->dropColumn('firstName');
            $table->dropColumn('patronymic');
            $table->dropColumn('fullName');
            $table->dropColumn('language');
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calculator_user_tokens', function(Blueprint $table){
            $table->string('outId')->nullable()->default(null);
            $table->string('lastName')->nullable()->default(null);
            $table->string('firstName')->nullable()->default(null);
            $table->string('patronymic')->nullable()->default(null);
            $table->string('fullName')->nullable()->default(null);
            $table->string('language')->nullable()->default(null);
            $table->string('currency')->nullable()->default(null);
        });
    }
};
