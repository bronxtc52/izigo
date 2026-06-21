<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autoship-подписка (Фаза 4). Списание с внутреннего USDT-баланса по расписанию.
 *
 * @property int $id
 * @property int $member_id
 * @property int $product_id
 * @property int $package_id
 * @property int $interval_days
 * @property \Illuminate\Support\Carbon $next_charge_at
 * @property string $status
 * @property int $retry_stage
 */
class AutoshipSubscription extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';

    /** Ступени повтора при нехватке средств (дни от первого провала). */
    public const RETRY_STAGES = [3, 7, 14];

    protected $fillable = [
        'member_id',
        'product_id',
        'package_id',
        'interval_days',
        'next_charge_at',
        'status',
        'retry_stage',
        'last_charge_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'product_id' => 'integer',
        'package_id' => 'integer',
        'interval_days' => 'integer',
        'next_charge_at' => 'datetime',
        'retry_stage' => 'integer',
        'last_charge_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
