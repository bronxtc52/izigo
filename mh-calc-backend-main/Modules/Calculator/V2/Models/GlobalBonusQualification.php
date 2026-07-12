<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T09: квалификация участника (rank>=Director) месяца (v2_global_bonus_qualifications).
 * shares = min(floor(referral_tree_pv / base_pv), max_shares). Строка есть и при
 * shares=0. КОНТРАКТ T10 (DEC-042): VP-этапы 2-3 = первые две записи shares>=1
 * при achieved_rank=VICE_PRESIDENT.
 *
 * @property int $id
 * @property int $global_bonus_month_id
 * @property int $member_id
 * @property string $achieved_rank
 * @property string $referral_tree_pv decimal(18,6)
 * @property string $base_pv decimal(18,6)
 * @property int $max_shares
 * @property int $shares
 */
class GlobalBonusQualification extends Model
{
    protected $table = 'v2_global_bonus_qualifications';

    protected $fillable = [
        'global_bonus_month_id',
        'member_id',
        'achieved_rank',
        'referral_tree_pv',
        'base_pv',
        'max_shares',
        'shares',
        'calculated_at',
    ];

    protected $casts = [
        'global_bonus_month_id' => 'integer',
        'member_id' => 'integer',
        'referral_tree_pv' => 'string',
        'base_pv' => 'string',
        'max_shares' => 'integer',
        'shares' => 'integer',
        'calculated_at' => 'immutable_datetime',
    ];
}
