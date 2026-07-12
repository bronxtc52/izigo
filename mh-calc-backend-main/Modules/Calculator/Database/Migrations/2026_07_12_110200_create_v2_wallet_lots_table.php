<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T02: кредит-лоты ОС/БС (lot-level expiry, DEC-015 earliest-expiry-first).
 * НС — плоский транзитный субсчёт БЕЗ лотов (BR-ACC-001/003).
 *
 *  - expires_at NULLABLE (amendments MF-9): null = лот НЕ сгорает (award-лоты БС T10);
 *    expireLots такие лоты пропускает.
 *  - origin_lot_id — связь БС-лота с истёкшим ОС-лотом (перенос остатка ОС→БС).
 *  - status: active | exhausted (выбран до нуля) | transferred (остаток ушёл ОС→БС)
 *    | expired (остаток БС аннулирован в company_expired_balance).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_wallet_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('account', 2); // os | bs
            $table->unsignedBigInteger('amount_cents');    // исходная сумма лота
            $table->unsignedBigInteger('available_cents'); // остаток
            $table->timestamp('earned_at');
            $table->timestamp('expires_at')->nullable(); // null = не сгорает (MF-9)
            $table->string('source_type', 32)->nullable(); // тип бонуса/награды (T06-T10)
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('origin_lot_id')->nullable()
                ->constrained('v2_wallet_lots')->nullOnDelete();
            $table->string('status', 12)->default('active');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();

            $table->index(['member_id', 'account', 'status', 'expires_at']);
            $table->index(['expires_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_wallet_lots');
    }
};
