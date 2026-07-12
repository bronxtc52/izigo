<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T08: строка лидерского бонуса (v2_leadership_bonus_lines). Одна на пару
 * (source_structure_bonus_id, receiver_member_id). status: accrued → posted (кредит ОС)
 * либо excluded (аудит) → reversed (T12, строки не удаляются).
 *
 * @property int $id
 * @property int $period_id
 * @property int $receiver_member_id
 * @property int $source_member_id
 * @property int $source_structure_bonus_id
 * @property int $depth
 * @property ?string $receiver_rank_key
 * @property ?string $receiver_tier
 * @property int $rate_bp
 * @property int $base_cents
 * @property int $amount_cents
 * @property string $status
 * @property ?string $exclusion_reason
 * @property ?int $blocking_member_id
 * @property ?int $policy_version_id
 * @property ?string $ledger_tx_id
 * @property ?array $explanation
 */
class LeadershipBonusLine extends Model
{
    public const STATUS_ACCRUED = 'accrued';
    public const STATUS_POSTED = 'posted';
    public const STATUS_EXCLUDED = 'excluded';
    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_TYPE = 'leadership';

    protected $table = 'v2_leadership_bonus_lines';

    protected $fillable = [
        'period_id',
        'receiver_member_id',
        'source_member_id',
        'source_structure_bonus_id',
        'depth',
        'receiver_rank_key',
        'receiver_tier',
        'rate_bp',
        'base_cents',
        'amount_cents',
        'status',
        'exclusion_reason',
        'blocking_member_id',
        'policy_version_id',
        'ledger_tx_id',
        'explanation',
    ];

    protected $casts = [
        'period_id' => 'integer',
        'receiver_member_id' => 'integer',
        'source_member_id' => 'integer',
        'source_structure_bonus_id' => 'integer',
        'depth' => 'integer',
        'rate_bp' => 'integer',
        'base_cents' => 'integer',
        'amount_cents' => 'integer',
        'blocking_member_id' => 'integer',
        'policy_version_id' => 'integer',
        'explanation' => 'array',
    ];
}
