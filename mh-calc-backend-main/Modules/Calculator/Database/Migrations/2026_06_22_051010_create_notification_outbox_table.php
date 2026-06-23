<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1 (Block C): очередь исходящих уведомлений (outbox) — фон проекта = планировщик
 * (НЕ Laravel queue). Диспетчер `notifications:outbox-dispatch` (everyMinute,
 * withoutOverlapping) выбирает pending где available_at<=now и шлёт через TelegramNotifier.
 * body — готовый Telegram-HTML; dedup_key обеспечивает идемпотентность enqueue.
 * chat_id — снимок telegram_id участника на момент постановки.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id');
            $table->string('channel')->default('telegram');
            $table->bigInteger('chat_id')->nullable(); // снимок telegram_id
            $table->string('kind', 64);
            $table->string('title')->nullable();
            $table->text('body');                       // готовый Telegram-HTML
            $table->json('data')->nullable();
            $table->string('dedup_key', 128)->nullable()->unique();
            $table->string('status')->default('pending'); // pending|sending|sent|failed|skipped
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->nullable();
            $table->text('last_error')->nullable();     // без токена бота
            $table->unsignedBigInteger('broadcast_id')->nullable();
            $table->timestamps();
            $table->timestamp('sent_at')->nullable();

            $table->index(['status', 'available_at']);
            $table->index(['member_id', 'status']);

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('broadcast_id')->references('id')->on('notification_broadcasts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
