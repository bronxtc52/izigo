<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T05 (mh-full-plan): фиче-флаг статусного слоя V2 — ВЫКЛЮЧЕН (deny-by-default,
 * по образцу C3/T03). Гейтит: StatusesStep в PaidOrderV2Pipeline, grace-скан
 * calc-v2:client-grace-scan и роуты v2_statuses. Требует включённого
 * mh_v2_volumes (статусы читают снапшоты/лоты/branch-stats T03).
 * Идемпотентно (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_statuses';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: лестница 12 статусов, CLIENT/grace, тиры контракта',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
