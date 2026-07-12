<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * mh-full-plan T04: фиче-флаг mh_plan_v2_periods — гейт scheduled-джобов периодов V2
 * (все 5 команд calc-v2:* при выключенном флаге — немедленный no-op). ВЫКЛЮЧЕН
 * (deny-by-default); admin-роуты периодов гейтит общий mh_plan_v2_admin (каркас W0).
 * Идемпотентно (insertOrIgnore): повторный прогон не переписывает enabled админа.
 */
return new class extends Migration {
    private const KEY = 'mh_plan_v2_periods';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: расчётные периоды — scheduled-джобы закрытий и переводов',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
