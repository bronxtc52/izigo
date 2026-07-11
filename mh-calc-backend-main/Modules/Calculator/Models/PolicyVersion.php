<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T01 (mh-full-plan V2): версия конфига политики (v2_policy_versions).
 * Статусы: draft (мутабельна) → active (единственная текущая, valid_to IS NULL)
 * → retired (закрытый интервал [valid_from, valid_to) для исторических расчётов).
 * Инварианты единственности active и непересечения интервалов enforce'ит
 * {@see \Modules\Calculator\V2\Services\PolicyVersionService} транзакционно
 * под lockForUpdate — НЕ мутировать строки в обход сервиса.
 */
class PolicyVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRED = 'retired';

    protected $table = 'v2_policy_versions';

    protected $fillable = [
        'code', 'status', 'schema_version', 'valid_from', 'valid_to',
        'config', 'config_hash', 'notes', 'created_by', 'activated_by', 'activated_at',
    ];

    protected $casts = [
        'config' => 'array',
        'schema_version' => 'integer',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'activated_at' => 'datetime',
    ];
}
