<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-chain выплаты USDT (Фаза 4, S7). Одна запись на попытку выплаты по заявке на вывод.
 * tx_hash виден партнёру для on-chain прозрачности. Суммы — целые USDT-центы.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payout_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_request_id')->constrained('withdrawal_requests')->cascadeOnDelete();
            $table->string('to_address');
            $table->unsignedBigInteger('amount_cents');
            $table->string('tx_hash')->nullable();
            $table->string('status', 12)->default('queued'); // queued|broadcast|confirmed|failed
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('withdrawal_request_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_transactions');
    }
};
