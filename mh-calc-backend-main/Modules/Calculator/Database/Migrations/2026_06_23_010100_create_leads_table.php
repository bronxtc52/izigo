<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лиды (потенциальные участники). Перешёл по реф-ссылке, но ещё НЕ купил пакет —
 * это лид, не Member: он ВНЕ бинар-дерева (не занимает слот), без ref_code (не
 * рекрутирует). Закреплён за спонсором (будущий личный реферал) на ограниченное
 * окно (expires_at). При первой подтверждённой оплате лид промоутится в Member
 * (PlacementService::place под замкнутого спонсора) и эта запись удаляется.
 * Спонсора можно менять, пока лид не оплатил; после оплаты — фиксируется навсегда.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->string('telegram_username')->nullable();
            $table->string('language')->nullable(); // user.language_code из Telegram
            $table->string('name')->nullable();
            // Замок-pending спонсор: всегда существующий Member. nullable из-за nullOnDelete;
            // непустоту на вставке гарантирует LeadService (нужен валидный ref спонсора).
            $table->foreignId('sponsor_id')->nullable()
                ->constrained('members')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index('sponsor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
