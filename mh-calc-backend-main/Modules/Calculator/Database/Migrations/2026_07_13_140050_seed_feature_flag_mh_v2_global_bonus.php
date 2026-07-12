<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T09 (mh-full-plan): фиче-флаг глобального бонуса — ВЫКЛЮЧЕН (deny-by-default,
 * образец C3/T05). Гейтит: month-close шаги аллокации/финализации, квартальный
 * payout-handler, команду calculator:v2:global-allocate и admin-роуты v2_global_bonus.
 * Идемпотентно (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_global_bonus';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: глобальный бонус (месячные пулы Director..VP, квартальная выплата)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
