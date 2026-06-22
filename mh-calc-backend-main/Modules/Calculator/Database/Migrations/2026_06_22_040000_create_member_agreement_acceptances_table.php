<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3 (онбординг/юр.согласия): факт акцепта пользовательского соглашения участником.
 * История акцептов (по одной строке на принятую версию) — текст и текущая версия живут в
 * plan_settings('agreement'). Аутентификация остаётся Telegram-only; здесь только согласие.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_agreement_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('accepted_at');
            $table->timestamps();

            // Один акцепт на (участник, версия) — повторный accept идемпотентен.
            $table->unique(['member_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_agreement_acceptances');
    }
};
