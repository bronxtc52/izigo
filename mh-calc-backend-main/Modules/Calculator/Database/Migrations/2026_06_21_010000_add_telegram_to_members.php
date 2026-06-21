<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Привязка Telegram-аккаунта к участнику для Mini App (резолв по telegram_id).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('ref_code');
            $table->string('telegram_username')->nullable()->after('telegram_id');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['telegram_id', 'telegram_username']);
        });
    }
};
