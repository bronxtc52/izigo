<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\Structure\Structure;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calculator_users', function (Blueprint $table) {
            $table->id();

            $table->string('email')->unique();
            $table->string('out_id')->nullable()->default(null);
            $table->string('last_name')->nullable()->default(null);
            $table->string('first_name')->nullable()->default(null);
            $table->string('patronymic')->nullable()->default(null);
            $table->string('full_name')->nullable()->default(null);
            $table->string('language')->nullable()->default(null);
            $table->string('currency')->nullable()->default(null);

            $table->timestamps();
        });

        Schema::table('calculator_structures', function (Blueprint $table) {
            $table->foreignId('calculator_user_id')->index()
                ->nullable()->default(null)
                ->references('id')
                ->on('calculator_users')
                ->onDelete('restrict');
        });

        Schema::table('calculator_user_tokens', function(Blueprint $table){
            $table->foreignId('calculator_user_id')->index()
                ->nullable()->default(null)
                ->references('id')
                ->on('calculator_users')
                ->onDelete('restrict');

            $table->dropUnique(['email']);
        });

        $tokenList = DB::table('calculator_user_tokens')->get();
        foreach ($tokenList as $token)
        {
            /** @var CalculatorUser $user */
            $user = CalculatorUser::query()->create([
                'email' => $token->email,
                'out_id' => $token->outId,
                'last_name' => $token->lastName,
                'first_name' => $token->firstName,
                'patronymic' => $token->patronymic,
                'full_name' => $token->fullName
            ]);

            DB::table('calculator_user_tokens')->where('id', $token->id)->update([
                'calculator_user_id' => $user->id
            ]);

            Structure::query()->where('calculator_user_token_id', $token->id)->update([
                'calculator_user_id' => $user->id
            ]);
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('calculator_structures', function (Blueprint $table) {
            $table->dropColumn('calculator_user_id');
        });

        Schema::table('calculator_user_tokens', function(Blueprint $table){
            $table->dropColumn('calculator_user_id');
            $table->unique('email');
        });

        Schema::dropIfExists('calculator_users');
    }
};
