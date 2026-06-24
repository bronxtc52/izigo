<?php

namespace Modules\Calculator\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Calculator\Models\FeatureFlag;

/**
 * C3 (Block C): начальный набор фиче-флагов Блока C — ВСЕ выключены (deny-by-default,
 * Gate-A п.8). Идемпотентно (firstOrCreate по key): повторный прогон НЕ переписывает уже
 * выставленное администратором значение enabled, только досоздаёт отсутствующие ключи.
 * Вызывается в docker/start.sh после migrate.
 */
class FeatureFlagSeeder extends Seeder
{
    public function run(): void
    {
        $flags = [
            'c1_notifications' => 'Уведомления партнёрам (inbox + Telegram)',
            'c2_helpdesk' => 'Служба поддержки (тикеты)',
            'c3_feature_flags' => 'Управление фиче-флагами',
            'c4_i18n_admin' => 'Переводы/локализация в админке',
            'c5_pii_export' => 'Экспорт данных с PII',
            'c6_copartners' => 'Со-партнёры участника',
            'c7_jobs_monitor' => 'Мониторинг очередей/outbox',
            'ai_assistant' => 'AI-ассистент партнёра (вопросы по KB через Claude API)',
        ];

        foreach ($flags as $key => $description) {
            FeatureFlag::query()->firstOrCreate(
                ['key' => $key],
                ['enabled' => false, 'description' => $description],
            );
        }
    }
}
