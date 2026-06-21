<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * События активации пакета (мок-оплата). idempotency_key обеспечивает exactly-once:
 * повторная отправка того же ключа не порождает повторного начисления.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('activation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('package_id')->constrained('calculator_packages');
            $table->string('idempotency_key')->unique();
            $table->string('status', 16)->default('applied');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_events');
    }
};
