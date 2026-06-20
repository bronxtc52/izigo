<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('calculator_users', function (Blueprint $table) {
            // Локальная авторизация без SSO (dev). У SSO-юзеров остаётся null.
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('calculator_users', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
