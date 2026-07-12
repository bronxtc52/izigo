<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T09 (mh-full-plan): квартальная выплата глобального пула на ОС (авто-проводка при
 * закрытии квартала; вывод — вручную, как весь контур). amount_cents = Σ final_cents
 * трёх финальных месяцев по участнику. idempotency_key v2:glb:q:{quarterId}:m:{memberId}
 * (unique) — двойной прогон закрытия квартала не задваивает деньги. unique(quarter, member).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_global_bonus_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quarter_period_id')->constrained('v2_calc_periods');
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key')->unique();
            $table->timestamp('posted_at')->nullable();
            $table->string('status', 16)->default('posted'); // posted | reversed
            $table->timestamps();

            $table->unique(['quarter_period_id', 'member_id'], 'v2_glb_payouts_quarter_member_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_global_bonus_payouts');
    }
};
