<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C2 (Block C): сообщения тикета (тред). author_role ∈ member|operator — кто написал
 * (партнёр или оператор). read_at — отметка прочтения противоположной стороной
 * (справочно). polling-курсор читателя — по id (since), не по времени.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->index();
            $table->unsignedBigInteger('author_member_id')->nullable();
            $table->string('author_role', 12); // member|operator
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
            $table->foreign('author_member_id')->references('id')->on('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
    }
};
