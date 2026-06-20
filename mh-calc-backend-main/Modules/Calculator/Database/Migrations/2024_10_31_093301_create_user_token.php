<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('calculator_user_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('token');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('token');
            $table->unique('email');
        });

        Schema::table('calculator_structures', function (Blueprint $table) {
            $table->foreignId('calculator_user_token_id')->index()
                ->nullable()->default(null)
                ->references('id')
                ->on('calculator_user_tokens')
                ->onDelete('restrict');
        });
    }

    public function down()
    {
        Schema::dropIfExists('calculator_user_tokens');
    }
};
