<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T09: аллокация пула по участнику или строка UNALLOCATED (member_id NULL, компания).
 * raw_cents (largest-remainder) → capped_cents (кап 25%) → final_cents (default=capped,
 * T11 перезаписывает). Квартальная выплата суммирует final_cents member-строк.
 *
 * @property int $id
 * @property int $global_bonus_month_id
 * @property int $pool_id
 * @property ?int $member_id
 * @property string $kind
 * @property int $shares
 * @property int $raw_cents
 * @property int $capped_cents
 * @property int $final_cents
 * @property string $status
 */
class GlobalBonusAllocation extends Model
{
    public const KIND_MEMBER = 'member';
    public const KIND_UNALLOCATED = 'unallocated';

    public const STATUS_ACCRUED = 'accrued';
    public const STATUS_PAID = 'paid';
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'v2_global_bonus_allocations';

    protected $fillable = [
        'global_bonus_month_id',
        'pool_id',
        'member_id',
        'kind',
        'shares',
        'raw_cents',
        'capped_cents',
        'final_cents',
        'status',
    ];

    protected $casts = [
        'global_bonus_month_id' => 'integer',
        'pool_id' => 'integer',
        'member_id' => 'integer',
        'shares' => 'integer',
        'raw_cents' => 'integer',
        'capped_cents' => 'integer',
        'final_cents' => 'integer',
    ];
}
