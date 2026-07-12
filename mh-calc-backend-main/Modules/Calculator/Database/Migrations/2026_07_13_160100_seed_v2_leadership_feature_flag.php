<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T08 (mh-full-plan): фиче-флаг лидерского бонуса V2 — ВЫКЛЮЧЕН (deny-by-default,
 * по образцу T07/T09/T10). Гейтит LeadershipCloseStep в пайплайне закрытия месяца,
 * команду calculator:v2:leadership-run и роуты v2_leadership. Идемпотентно
 * (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_leadership';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: лидерский бонус глубиной до 7 (START 10% / BUSINESS 15% / ELITE 20-1% по статусной глубине), на ОС',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
