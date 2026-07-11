<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T03 (mh-full-plan): фиче-флаг volume-слоя V2 — ВЫКЛЮЧЕН (deny-by-default).
 * Независим от общего cutover-флага mh_plan_v2_engine (T15): объёмный слой можно
 * включить в shadow-режиме раньше денег. Гейтит: снапшот/лоты в markPaid и
 * admin-роуты v2_volumes. Идемпотентно (insertOrIgnore): повторный прогон не
 * перетирает значение, выставленное администратором.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_volumes';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: volume-слой (PV/BV-снапшоты, PV-лоты бинара, матчинг)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
