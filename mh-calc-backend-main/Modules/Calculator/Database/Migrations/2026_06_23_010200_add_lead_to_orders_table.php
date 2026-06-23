<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заказ может принадлежать ЛИДУ до первой оплаты: тогда member_id ещё нет (лид вне
 * дерева), а lead_id указывает на pending-лида. При подтверждённой оплате лид
 * промоутится в Member и member_id заполняется (backfill в OrderService::markPaid).
 * Поэтому member_id становится nullable + добавляется lead_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('member_id')
                ->constrained('leads')->nullOnDelete();
        });

        // Прод и тесты — PostgreSQL (ltree/ilike). member_id больше не обязателен:
        // у lead-заказа он null до промоушна.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN member_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            // Откат: вернуть NOT NULL безопасно только при отсутствии lead-заказов.
            DB::statement('ALTER TABLE orders ALTER COLUMN member_id SET NOT NULL');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
