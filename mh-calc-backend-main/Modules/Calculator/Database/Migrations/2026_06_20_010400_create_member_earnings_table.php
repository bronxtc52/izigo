<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Агрегат дохода участника (снимок): итог + разбивка по типам бонусов.
 * Перестраивается при каждом пересчёте; источник правды — member_bonus_lines.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('member_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->decimal('total', 20, 2)->default(0);
            $table->json('by_type')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_earnings');
    }
};
