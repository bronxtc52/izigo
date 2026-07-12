<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T07 (mh-full-plan): реферальная премия по тирам (CAL-REF-001). Строка на каждый
 * начисленный/пропущенный уровень реферального дерева (depth 1|2) заказа. Деньги —
 * integer USD-центы; ставки — integer basis points.
 *
 * Инварианты денег/провенанса:
 *  - gross_cents = intdiv(base_bv_cents * rate_bps, 10000), floor (DEC-002: 90 979.2 → 90 979);
 *  - net_cents NULLABLE — заполняет T11 после месячной 60%-калибровки (NULL = не калибровано;
 *    премия постится на ОС СРАЗУ, gross != net не инвариант — риск-карта Гейта A);
 *  - status posted → есть ledger-проводка ОС (ledger_idempotency_key заполнен);
 *    zero_rate / blocked_elite → explain-строка БЕЗ денег (ledger_idempotency_key NULL);
 *  - reversed_at/reversal_reason — заполняет T12 (провенанс сторно; логика сторно — T12);
 *  - UNIQUE(order_id, depth) — ключ идемпотентности (повтор markPaid/webhook = no-op).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('source_member_id')->constrained('members');       // покупатель
            $table->foreignId('beneficiary_member_id')->constrained('members');  // получатель премии
            $table->smallInteger('depth');                                        // 1 | 2
            $table->string('tier_snapshot', 16)->nullable();                     // тир получателя на paid_at (T05), null = ниже START
            $table->integer('rate_bps');                                         // ставка bps (1000/500/800/0)
            $table->bigInteger('base_bv_cents');                                 // снапшот базы BV заказа
            $table->bigInteger('gross_cents');                                   // начисление до калибровки
            $table->bigInteger('net_cents')->nullable();                         // T11: после 60%-пула (null = не калибровано)
            $table->string('status', 24);                                        // posted | zero_rate | blocked_elite
            $table->unsignedBigInteger('policy_version_id');                     // T01 (транзитивно)
            $table->timestamp('paid_at');                                        // момент оплаты заказа-триггера
            $table->string('ledger_idempotency_key')->nullable();               // v2:referral:order:{id}:d{depth} (только posted)
            $table->json('explain');                                             // входы формулы (DEC-054)
            $table->timestamp('reversed_at')->nullable();                        // T12
            $table->string('reversal_reason')->nullable();                       // T12
            $table->timestamps();

            $table->unique(['order_id', 'depth'], 'v2_referral_rewards_order_depth_uq');
            $table->index(['beneficiary_member_id', 'paid_at'], 'v2_referral_rewards_beneficiary_ix');
            $table->index('paid_at', 'v2_referral_rewards_paid_at_ix'); // пул-финализация T11
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_referral_rewards ADD CONSTRAINT v2_referral_rewards_status_ck "
                . "CHECK (status IN ('posted', 'zero_rate', 'blocked_elite'))"
            );
            DB::statement(
                "ALTER TABLE v2_referral_rewards ADD CONSTRAINT v2_referral_rewards_depth_ck "
                . "CHECK (depth IN (1, 2))"
            );
            DB::statement(
                "ALTER TABLE v2_referral_rewards ADD CONSTRAINT v2_referral_rewards_tier_ck "
                . "CHECK (tier_snapshot IS NULL OR tier_snapshot IN ('START', 'BUSINESS', 'ELITE'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_referral_rewards');
    }
};
