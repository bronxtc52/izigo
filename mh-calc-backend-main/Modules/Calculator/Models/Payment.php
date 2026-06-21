<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Платёж приёма (Фаза 4). purpose: order|topup. Суммы — целые USDT-центы.
 *
 * @property int $id
 * @property ?int $order_id
 * @property int $member_id
 * @property string $purpose
 * @property int $amount_cents
 * @property string $status
 * @property string $external_ref
 * @property ?string $external_id
 */
class Payment extends Model
{
    public const PURPOSE_ORDER = 'order';
    public const PURPOSE_TOPUP = 'topup';

    public const STATUS_CREATED = 'created';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'order_id',
        'member_id',
        'provider',
        'purpose',
        'amount_cents',
        'currency',
        'status',
        'external_ref',
        'external_id',
        'raw_payload',
        'paid_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'member_id' => 'integer',
        'amount_cents' => 'integer',
        'raw_payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
