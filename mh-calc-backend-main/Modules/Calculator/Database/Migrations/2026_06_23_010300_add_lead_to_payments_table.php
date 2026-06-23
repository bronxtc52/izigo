<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Платёж может принадлежать ЛИДУ до промоушна (lead-заказ): member_id ещё нет,
 * lead_id указывает на pending-лида. external_ref="pay:{id}" (memo) выдаётся как
 * обычно. При подтверждении лид промоутится в Member, member_id заполняется.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('member_id')
                ->constrained('leads')->nullOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ALTER COLUMN member_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payments ALTER COLUMN member_id SET NOT NULL');
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
        });
    }
};
