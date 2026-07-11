<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * mh-full-plan W0 scaffold: фиче-флаги V2-движка — все ВЫКЛЮЧЕНЫ (deny-by-default,
 * паттерн Block C / FeatureFlagSeeder). Гейтят: mh_plan_v2_engine — расчёт V2 в
 * точке оплаты/активации (cutover T15), mh_plan_v2_admin — V2 admin-роуты,
 * mh_plan_v2_miniapp — V2 cabinet-роуты Mini App.
 *
 * Идемпотентно (insertOrIgnore по unique key): повторный прогон НЕ переписывает
 * значение enabled, выставленное администратором. Слот миграции —
 * docs/mh-full-plan-migration-ledger.md (W0 = 2026_07_12_09xxxx).
 */
return new class extends Migration {
    private const FLAGS = [
        'mh_plan_v2_engine' => 'MH-план V2: расчётный движок (cutover V1→V2)',
        'mh_plan_v2_admin' => 'MH-план V2: админ-разделы (политика, счета, периоды)',
        'mh_plan_v2_miniapp' => 'MH-план V2: разделы Mini App (счета, статусы)',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::FLAGS as $key => $description) {
            DB::table('feature_flags')->insertOrIgnore([
                'key' => $key,
                'enabled' => false,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('feature_flags')->whereIn('key', array_keys(self::FLAGS))->delete();
    }
};
