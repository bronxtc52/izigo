<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T02: трассировка движений по лотам (DEC-015 full traceability).
 *
 * Семантика строки: amount_cents ВСЕГДА > 0, направление задаёт reason:
 *  - «из лота» (available_cents уменьшился): order_reserve | withdrawal_hold |
 *    expiry_transfer | expiry_annul | debit
 *  - «обратно в лот» (available_cents вырос): reserve_release | reversal
 * Инвариант лота: available = amount − Σ(из лота) + Σ(обратно).
 *
 * T12 (возвраты) будет ИСПОЛЬЗОВАТЬ схему (reason=reversal, tx_id) — менять её в T12 нельзя.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_wallet_lot_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lot_id')->constrained('v2_wallet_lots')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->string('reason', 24);
            $table->uuid('tx_id')->nullable(); // группа проводок ledger той же операции
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('withdrawal_request_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('lot_id');
            $table->index(['reason', 'reservation_id']);
            $table->index(['reason', 'withdrawal_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_wallet_lot_consumptions');
    }
};
