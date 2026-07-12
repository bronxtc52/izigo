<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T11 (mh-full-plan): фиче-флаг 60%-калибровки — ВЫКЛЮЧЕН (deny-by-default, образец
 * T05/T09). Гейтит: month-close шаг калибровки (PoolCalibrationCloseStep — no-op при
 * OFF), команду calc-v2:pool-calibrate и admin-роуты v2_pool. Идемпотентно
 * (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_pool';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: 60%-калибровка выплат (payout pool, DEC-014/029/053)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
