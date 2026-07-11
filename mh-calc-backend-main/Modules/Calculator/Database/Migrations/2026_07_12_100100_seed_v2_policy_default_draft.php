<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyConfigValidator;

/**
 * mh-full-plan T01: сид версии-DRAFT mh-v2-usd-1 с каноническим конфигом
 * DefaultPolicyConfig::doc(). НЕ активирует (активация — руками owner через
 * админ-endpoint или cutover T15). Идемпотентно: insertOrIgnore по unique(code),
 * повторный прогон не перепишет правки владельца. Конфиг прогоняется через
 * валидатор ДО вставки — битый сид падает на миграции, а не в рантайме расчёта.
 */
return new class extends Migration {
    public function up(): void
    {
        $doc = (new PolicyConfigValidator())->validate(DefaultPolicyConfig::doc());
        $now = now();

        DB::table('v2_policy_versions')->insertOrIgnore([
            'code' => DefaultPolicyConfig::CODE,
            'status' => 'draft',
            'schema_version' => DefaultPolicyConfig::SCHEMA_VERSION,
            'valid_from' => null,
            'valid_to' => null,
            'config' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'config_hash' => DefaultPolicyConfig::canonicalHash($doc),
            'notes' => 'Канонический конфиг полного плана MH (Гейт A, 468 KZT = 1 USD). Сид W1/T01.',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Удаляем только нетронутый draft-сид; активированную/правленную версию не трогаем.
        DB::table('v2_policy_versions')
            ->where('code', DefaultPolicyConfig::CODE)
            ->where('status', 'draft')
            ->delete();
    }
};
