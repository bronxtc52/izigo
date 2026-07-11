<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T03: проекция ролей веток бинара (пересоздаваемая из v2_pv_lots).
 * large_side по lifetime PV; равенство => NULL (tie). small_branch_lifetime_pv —
 * контракт порогов малой ветки для T05.
 *
 * @property int $member_id
 * @property string $left_free_pv
 * @property string $right_free_pv
 * @property string $left_lifetime_pv
 * @property string $right_lifetime_pv
 * @property ?string $large_side
 * @property string $small_branch_lifetime_pv
 */
class MemberBranchStat extends Model
{
    protected $table = 'v2_member_branch_stats';

    protected $primaryKey = 'member_id';

    public $incrementing = false;

    public $timestamps = false; // recomputed_at ведём сами

    protected $fillable = [
        'member_id',
        'left_free_pv',
        'right_free_pv',
        'left_lifetime_pv',
        'right_lifetime_pv',
        'large_side',
        'small_branch_lifetime_pv',
        'recomputed_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'left_free_pv' => 'string',
        'right_free_pv' => 'string',
        'left_lifetime_pv' => 'string',
        'right_lifetime_pv' => 'string',
        'small_branch_lifetime_pv' => 'string',
        'recomputed_at' => 'datetime',
    ];
}
