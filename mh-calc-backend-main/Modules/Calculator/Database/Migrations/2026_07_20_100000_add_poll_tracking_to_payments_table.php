<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * t2 (P2-tails): персистентная наблюдаемость опроса платежей (tonpay-poll).
 * Аддитивно к payments:
 *  - last_poll_result — исход последнего опроса: paid|pending|failed|none|error
 *    (семантика PaymentGateway::pollStatus; НЕ enum-check, чтобы не плодить миграции);
 *  - last_polled_at — когда опрашивали в последний раз;
 *  - poll_error_streak — подряд-ошибки опроса; сбрасывается в 0 любым успешным опросом.
 * Инвариант B4 сохранён: payments.status НИКОГДА не принимает 'error' — 'error'
 * живёт только в last_poll_result. Префикс 100000 — по p2-tails-migration-ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('last_poll_result', 16)->nullable();
            $table->timestampTz('last_polled_at')->nullable();
            $table->integer('poll_error_streak')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['last_poll_result', 'last_polled_at', 'poll_error_streak']);
        });
    }
};
