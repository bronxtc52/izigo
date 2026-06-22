<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C2 (Block C): helpdesk — тикеты поддержки. Партнёр заводит тикет (cabinet,
 * telegram.auth) и переписывается с оператором (owner/support). Без priority и
 * вложений (контракт Gate-A п.7), транспорт чтения — polling 5–8с (п.6).
 *
 * status ∈ open|in_progress|resolved|closed. assigned_to — оператор, взявший тикет
 * (nullable). last_message_at — для сортировки очереди по свежести.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->string('subject', 160);
            $table->string('status', 12)->default('open'); // open|in_progress|resolved|closed
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
