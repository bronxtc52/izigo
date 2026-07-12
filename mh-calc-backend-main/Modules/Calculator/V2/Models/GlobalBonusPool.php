<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * T09: статусный пул месяца (v2_global_bonus_pools). pool_amount_cents = intdiv(
 * global_bv_cents * rate_bps, 10000). allocated + unallocated == pool_amount (инвариант).
 *
 * @property int $id
 * @property int $global_bonus_month_id
 * @property string $pool_rank
 * @property int $rate_bps
 * @property int $pool_amount_cents
 * @property int $total_shares
 * @property int $allocated_cents
 * @property int $unallocated_cents
 * @property ?string $unallocated_reason
 */
class GlobalBonusPool extends Model
{
    public const RANK_DIRECTOR = 'director';
    public const RANK_PEARL = 'pearl';
    public const RANK_SAPPHIRE = 'sapphire';
    public const RANK_DIAMOND = 'diamond';
    public const RANK_VP = 'vp';

    public const REASON_CAP_REMAINDER = 'cap_remainder';
    public const REASON_EMPTY_POOL = 'empty_pool';
    public const REASON_ROUNDING = 'rounding';

    protected $table = 'v2_global_bonus_pools';

    protected $fillable = [
        'global_bonus_month_id',
        'pool_rank',
        'rate_bps',
        'pool_amount_cents',
        'total_shares',
        'allocated_cents',
        'unallocated_cents',
        'unallocated_reason',
    ];

    protected $casts = [
        'global_bonus_month_id' => 'integer',
        'rate_bps' => 'integer',
        'pool_amount_cents' => 'integer',
        'total_shares' => 'integer',
        'allocated_cents' => 'integer',
        'unallocated_cents' => 'integer',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(GlobalBonusAllocation::class, 'pool_id');
    }
}
