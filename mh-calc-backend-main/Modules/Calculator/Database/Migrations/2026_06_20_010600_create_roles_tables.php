<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Каркас RBAC (схема в S1; гейты/политики — S3). 4 фикс-роли + pivot к пользователям.
 * leader_scope_member_id ограничивает видимость лидера его поддеревом.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique(); // owner|finance|leader|support
            $table->string('label')->nullable();
        });

        foreach ([
            'owner' => 'Владелец',
            'finance' => 'Финансы',
            'leader' => 'Лидер',
            'support' => 'Саппорт',
        ] as $name => $label) {
            DB::table('roles')->insert(['name' => $name, 'label' => $label]);
        }

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('calculator_user_id')->constrained('calculator_users')->cascadeOnDelete();
            $table->foreignId('leader_scope_member_id')->nullable()
                ->constrained('members')->nullOnDelete();

            $table->unique(['role_id', 'calculator_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
    }
};
