<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1 (Block C): журнал рассылок (broadcasts). Создаётся при отправке рассылки
 * админом (owner/support): кто, какому сегменту, сырой текст, охват, статус.
 * Применяется ПЕРВОЙ в диапазоне 0510xx — на неё ссылается notification_outbox.broadcast_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_member_id')->nullable();
            $table->string('segment_type');          // all | by_status | by_rank
            $table->string('segment_value')->nullable();
            $table->text('body_raw');                 // сырой markdown от админа (нормализуется на выходе)
            $table->integer('recipients_count')->default(0);
            $table->string('status')->default('preview'); // preview|queued|processing|done
            $table->timestamp('created_at')->nullable();
            $table->timestamp('queued_at')->nullable();

            $table->foreign('actor_member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_broadcasts');
    }
};
