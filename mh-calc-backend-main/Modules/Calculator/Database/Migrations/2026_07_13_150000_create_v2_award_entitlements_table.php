<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * mh-full-plan T10: квалификационные награды USD (единоразовые суммы за статусы
 * Manager..VP на Бонусный счёт, ручная выплата). Entitlement — источник истины
 * «мы должны участнику X»; денежная проводка на БС создаётся кредит-лотом T02
 * (source_type='award', expires_at NULL — не сгорает, MF-9).
 *
 *  - UNIQUE(member_id, award_code, stage_no) — идемпотентный триггер наград
 *    (DEC-040/BR-AWD-002): повторная доставка того же rank-события/квалификации
 *    не создаёт дублей; для VP — три этапа (stage 1 при достижении ранга, 2/3 —
 *    по квалификациям глобального бонуса, DEC-042).
 *  - amount_cents — снапшот суммы из PolicyVersion на момент гранта (провенанс).
 *  - status: granted (начислено на БС, ждёт ручной выплаты) | on_hold (пауза
 *    админом) | paid_out (выплачено вручную, проводка БС→payouts_paid) |
 *    forfeited (админ решил не выплачивать; начисление НЕ удаляется — DEC-041/043,
 *    reversal-проводок по наградам нет, DEC-027 «ранг навсегда»).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_award_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            // MANAGER|BRONZE_MANAGER|SILVER_MANAGER|GOLD_MANAGER|PLATINUM_MANAGER|
            // DIRECTOR|PEARL_DIRECTOR|SAPPHIRE_DIRECTOR|DIAMOND_DIRECTOR|VICE_PRESIDENT
            $table->string('award_code', 32);
            $table->unsignedSmallInteger('stage_no')->default(1); // VP: 1..3, прочие: 1
            $table->unsignedBigInteger('amount_cents'); // integer USD-центы, снапшот политики
            $table->unsignedBigInteger('policy_version_id')->nullable(); // провенанс T01
            $table->string('trigger_type', 24); // rank_achieved | global_qualification
            $table->string('trigger_ref', 64)->nullable(); // id ранг-строки T05 либо ключ месяца YYYY-MM
            $table->string('status', 12)->default('granted');
            $table->timestamp('granted_at');
            $table->timestamp('posted_at')->nullable(); // момент проводки на БС
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_admin_id')->nullable();
            $table->text('note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'award_code', 'stage_no'], 'v2_award_entitlements_uq');
            $table->index('status', 'v2_award_entitlements_status_ix');
            $table->index('member_id', 'v2_award_entitlements_member_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_award_entitlements');
    }
};
