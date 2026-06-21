<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Настройки маркетинг-плана, редактируемые из админки (проценты/пороги/режим размещения).
 * Доменный Plan строится из дефолтов фабрики + оверрайдов отсюда. value — JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Режим размещения компании по умолчанию: авто-спилловер в слабую ногу.
        DB::table('plan_settings')->insert([
            'key' => 'placement_mode',
            'value' => json_encode('auto'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_settings');
    }
};
