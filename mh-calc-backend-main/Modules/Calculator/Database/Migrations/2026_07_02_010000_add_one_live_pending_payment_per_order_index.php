<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Один живой pending-инвойс на заказ (MAJOR G5). Партиал-unique индекс: на конкретный заказ
 * может существовать максимум ОДИН платёж в статусе created|pending. Терминальные статусы
 * (paid|failed|expired) из индекса выпадают, поэтому после истечения/провала инвойса заказ
 * можно оплатить заново новым инвойсом. topup (order_id IS NULL) индекс не касается.
 *
 * Форвард-онли, ltree-независимо. Postgres-only (partial unique index).
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // ⚠️ ДАННЫЕ ПЕРЕД ИНДЕКСОМ. Индекс чинит баг «несколько живых pending на заказ» — значит
        // в проде такие дубли МОГУТ уже существовать. `CREATE UNIQUE INDEX` на дублях упал бы, а
        // т.к. миграция гоняется из строгого docker/start.sh (без `|| true`), это уронило бы старт
        // контейнера. Поэтому сначала схлопываем дубли: на каждый order_id оставляем ОДИН живой
        // инвойс (самый свежий, max(id) — его и переиспользует reuseLivePendingInvoice), остальные
        // помечаем 'expired'. Деньги не теряются: если по устаревшему memo реально пришёл перевод,
        // recheckAdmin восстановит его (expired → paid). Идемпотентно (повторный прогон — no-op).
        DB::statement(
            "UPDATE payments SET status = 'expired' "
            . "WHERE status IN ('created', 'pending') AND order_id IS NOT NULL "
            . "AND id NOT IN ("
            . "  SELECT MAX(id) FROM payments "
            . "  WHERE status IN ('created', 'pending') AND order_id IS NOT NULL "
            . "  GROUP BY order_id"
            . ")"
        );

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS payments_one_live_pending_per_order "
            . "ON payments (order_id) "
            . "WHERE status IN ('created', 'pending') AND order_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS payments_one_live_pending_per_order');
        }
    }
};
