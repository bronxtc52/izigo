<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * T05: append-only снапшот оценки квалификации (v2_qualification_evaluations,
 * BR-RANK-002): вариант, квалифаеры с корневыми ветвями и рангами на as_of,
 * per-criterion разбор, evidence_hash.
 *
 * @property string $id uuid
 * @property int $member_id
 * @property string $target_rank_code
 * @property ?string $variant_used
 * @property bool $passed
 * @property ?array $qualifiers_json
 * @property ?array $criteria_json
 * @property string $evidence_hash
 * @property string $trigger order|grace|manual|migration
 */
class QualificationEvaluation extends Model
{
    use HasUuids;

    public const TRIGGER_ORDER = 'order';
    public const TRIGGER_GRACE = 'grace';
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_MIGRATION = 'migration';

    protected $table = 'v2_qualification_evaluations';

    public $timestamps = false; // только created_at при insert

    protected $fillable = [
        'member_id',
        'target_rank_code',
        'as_of',
        'policy_version_id',
        'small_branch_pv',
        'variant_used',
        'passed',
        'qualifiers_json',
        'criteria_json',
        'evidence_hash',
        'trigger',
        'created_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'as_of' => 'datetime',
        'policy_version_id' => 'integer',
        'small_branch_pv' => 'string',
        'passed' => 'boolean',
        'qualifiers_json' => 'array',
        'criteria_json' => 'array',
        'created_at' => 'datetime',
    ];
}
