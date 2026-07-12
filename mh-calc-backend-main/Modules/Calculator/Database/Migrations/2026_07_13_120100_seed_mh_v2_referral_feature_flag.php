<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T07 (mh-full-plan): фиче-флаг реферальной премии V2 — ВЫКЛЮЧЕН (deny-by-default,
 * по образцу T03/T05). Гейтит ReferralBonusStep в PaidOrderV2Pipeline и роуты
 * v2_referral. Требует включённого mh_v2_volumes (реферальная берёт базу BV из
 * снапшотов заказа T03). Идемпотентно (insertOrIgnore): повторный прогон не
 * перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_referral';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: реферальная премия по тирам (10% L1 / 0-5-8% L2), на ОС сразу после оплаты',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
