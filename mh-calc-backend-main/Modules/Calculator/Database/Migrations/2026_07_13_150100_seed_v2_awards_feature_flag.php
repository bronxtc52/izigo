<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T10 (mh-full-plan): фиче-флаг квалификационных наград V2 — ВЫКЛЮЧЕН
 * (deny-by-default, по образцу C3/T03/T05). Гейтит: AwardsStep в
 * PaidOrderV2Pipeline (грант наград при достижении ранга) и роуты v2_awards
 * (cabinet + admin). Награды читают v2_rank_history (T05) — при выключенном
 * mh_v2_statuses ранги не пишутся и наград нет.
 * Идемпотентно (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_awards';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: квалификационные награды USD (Manager..VP) на Бонусный счёт, ручная выплата',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
