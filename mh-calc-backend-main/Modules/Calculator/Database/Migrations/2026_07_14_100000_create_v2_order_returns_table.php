<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T12 (mh-full-plan): возвраты/сторно V2. Заголовок возврата заказа
 * (v2_order_returns) — одна строка на факт возврата, оркеструет reversal-chain.
 * Деньги — integer USD-центы; PV — decimal(18,6) (amendments nice-to-have #3).
 *
 * Возврат денег покупателю (USDT) — ВНЕ системы (админ платит руками, решение
 * владельца dec-triage DEC-012/027): система только фиксирует факт и сторнирует
 * ВНУТРЕННИЕ начисления даунлайну. Покупателю на ОС ничего не зачисляется.
 *
 * status: draft → reversing → reversed (успех) | needs_manual (закрытые периоды/
 * каскад требует ручной корректировки) | failed. idempotency_key уникален —
 * повторный POST того же возврата = no-op (DEC-012: возвраты редкие, ручные).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_order_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('member_id')->constrained('members'); // покупатель
            $table->string('kind', 16);                             // full | partial
            $table->string('status', 24)->default('draft');         // draft|reversing|reversed|needs_manual|failed
            $table->text('reason');
            $table->bigInteger('returned_bv_cents')->default(0);    // снапшот из OrderItem (DEC-003)
            $table->decimal('returned_pv', 18, 6)->default(0);
            $table->unsignedBigInteger('policy_version_id');
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'v2_order_returns_order_status_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_order_returns ADD CONSTRAINT v2_order_returns_kind_ck "
                . "CHECK (kind IN ('full', 'partial'))"
            );
            DB::statement(
                "ALTER TABLE v2_order_returns ADD CONSTRAINT v2_order_returns_status_ck "
                . "CHECK (status IN ('draft', 'reversing', 'reversed', 'needs_manual', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_returns');
    }
};
