<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Платежи приёма (Фаза 4). Один платёж = оплата заказа (purpose=order) ИЛИ пополнение
 * баланса (purpose=topup). Подтверждение — только по webhook (идемпотентно по external_ref).
 * Суммы — целые USDT-центы.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('provider', 24)->default('wallet_pay');
            $table->string('purpose', 16); // order|topup
            $table->unsignedBigInteger('amount_cents');
            $table->string('currency', 8)->default('USDT');
            $table->string('status', 16)->default('created'); // created|pending|paid|failed|expired
            $table->string('external_ref')->nullable()->unique(); // наш "pay:{id}"; null до выдачи инвойса (PG: много NULL под unique)
            $table->string('external_id')->nullable();        // id инвойса на стороне шлюза
            $table->json('raw_payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'status']);
            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
