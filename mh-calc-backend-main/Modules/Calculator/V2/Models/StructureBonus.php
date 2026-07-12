<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T06: строка структурной премии окна half-month (v2_structure_bonuses).
 * Одна на (period_id, member_id). status: calculated → posted (кредит НС) →
 * reversed (T12). net_cents = after_cap_cents до появления 60%-пула T11 (тогда
 * pool_* заполнятся и net пересчитается ПЕРЕД posting).
 *
 * @property int $id
 * @property int $period_id
 * @property int $member_id
 * @property ?int $policy_version_id
 * @property string $rank_code
 * @property int $rate_bps
 * @property string $matched_pv
 * @property int $matched_bv_cents
 * @property ?int $match_group_id
 * @property int $gross_cents
 * @property int $half_cap_cents
 * @property int $monthly_cap_cents
 * @property int $cap_remaining_before_cents
 * @property int $after_cap_cents
 * @property int $forfeited_cents
 * @property ?string $pool_coefficient
 * @property ?int $pool_adjustment_cents
 * @property int $net_cents
 * @property string $accrual_month
 * @property string $status
 * @property ?string $posting_idempotency_key
 * @property ?array $explanation
 */
class StructureBonus extends Model
{
    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_TYPE = 'structure_bonus';

    protected $table = 'v2_structure_bonuses';

    protected $fillable = [
        'period_id',
        'member_id',
        'policy_version_id',
        'rank_code',
        'rate_bps',
        'matched_pv',
        'matched_bv_cents',
        'match_group_id',
        'gross_cents',
        'half_cap_cents',
        'monthly_cap_cents',
        'cap_remaining_before_cents',
        'after_cap_cents',
        'forfeited_cents',
        'pool_coefficient',
        'pool_adjustment_cents',
        'net_cents',
        'accrual_month',
        'status',
        'posting_idempotency_key',
        'explanation',
    ];

    protected $casts = [
        'period_id' => 'integer',
        'member_id' => 'integer',
        'policy_version_id' => 'integer',
        'rate_bps' => 'integer',
        'matched_pv' => 'string',
        'matched_bv_cents' => 'integer',
        'match_group_id' => 'integer',
        'gross_cents' => 'integer',
        'half_cap_cents' => 'integer',
        'monthly_cap_cents' => 'integer',
        'cap_remaining_before_cents' => 'integer',
        'after_cap_cents' => 'integer',
        'forfeited_cents' => 'integer',
        'pool_adjustment_cents' => 'integer',
        'net_cents' => 'integer',
        'explanation' => 'array',
    ];
}
