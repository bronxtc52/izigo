<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1 (Block C): входящие уведомления партнёра (inbox, в приложении). Заполняется
 * NotificationService::enqueue* при inbox=true в одной транзакции с outbox. Партнёр
 * видит только свои записи (cabinet, telegram.auth); read_at — отметка прочтения.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_inbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->string('kind', 64);
            $table->string('title');
            $table->text('body');                  // готовый Telegram-HTML (рендерится и в Mini App)
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'read_at']);

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_inbox');
    }
};
