<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * T12 (mh-full-plan): фиче-флаг возвратов/сторно V2 — ВЫКЛЮЧЕН (deny-by-default,
 * по образцу C3/T03/T05/T10). Гейтит: роуты v2_refunds (admin) и guard в
 * OrderService::setStatus (запрет прямого paid→refunded мимо RefundService).
 * Пока флаг OFF — поведение прода не меняется (возврат = смена статуса как в V1).
 * Идемпотентно (insertOrIgnore): повторный прогон не перетирает значение админа.
 */
return new class extends Migration {
    private const KEY = 'mh_v2_refunds';

    public function up(): void
    {
        $now = now();
        DB::table('feature_flags')->insertOrIgnore([
            'key' => self::KEY,
            'enabled' => false,
            'description' => 'MH-план V2: возвраты/сторно (reversal всех бонусов, корректировки закрытых периодов, ручной возврат средств вне системы)',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('feature_flags')->where('key', self::KEY)->delete();
    }
};
