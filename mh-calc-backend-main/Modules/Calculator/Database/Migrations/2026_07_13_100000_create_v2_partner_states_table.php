<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T05 (mh-full-plan): денормализованная проекция статуса участника в движке V2 —
 * жизненный цикл CLIENT/grace (BR-REG-004), текущий достигнутый ранг лестницы
 * (DEC-020: ранг навсегда) и тир контракта (BR-TIER-001, тир не понижается).
 * Читают T06/T07/T14; исторические as-of чтения — ТОЛЬКО через v2_rank_history /
 * v2_tier_history (контракт волны), эта таблица — «сейчас».
 *
 * Канон grace — amendments MF-7: отдельного state client_grace НЕТ; grace = state
 * 'client' + grace_expires_at; сканер ловит state='client' AND grace_expires_at <
 * now(); терминальный исход — 'grace_expired'.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_partner_states', function (Blueprint $table) {
            $table->foreignId('member_id')->primary()->constrained('members');
            $table->string('state', 16)->default('none'); // none|client|consultant|grace_expired
            $table->string('current_rank_code', 32)->nullable();
            $table->string('current_tier', 16)->nullable(); // START|BUSINESS|ELITE
            // Накопленный personal PV (Σ v2_order_volume_snapshots участника) —
            // база тира; decimal(18,6) как во всех V2-таблицах (amendments NTH-3).
            $table->decimal('personal_pv_total', 18, 6)->default(0);
            $table->timestamp('client_achieved_at')->nullable();
            $table->timestamp('grace_started_at')->nullable();
            // Дедлайн grace: конец 30-го календарного дня 23:59:59 Asia/Almaty,
            // хранится в UTC (DEC-006/DEC-026 вариант B «включительно»).
            $table->timestamp('grace_expires_at')->nullable();
            $table->string('grace_outcome', 12)->nullable(); // consultant|annulled
            $table->timestamp('grace_annulled_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'grace_expires_at'], 'v2_partner_states_grace_scan_ix');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE v2_partner_states ADD CONSTRAINT v2_partner_states_state_ck "
                . "CHECK (state IN ('none', 'client', 'consultant', 'grace_expired'))"
            );
            DB::statement(
                "ALTER TABLE v2_partner_states ADD CONSTRAINT v2_partner_states_grace_outcome_ck "
                . "CHECK (grace_outcome IS NULL OR grace_outcome IN ('consultant', 'annulled'))"
            );
            DB::statement(
                "ALTER TABLE v2_partner_states ADD CONSTRAINT v2_partner_states_tier_ck "
                . "CHECK (current_tier IS NULL OR current_tier IN ('START', 'BUSINESS', 'ELITE'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_partner_states');
    }
};
