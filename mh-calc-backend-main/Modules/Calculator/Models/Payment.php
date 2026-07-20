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
 * @property ?string $last_poll_result исход последнего опроса: paid|pending|failed|none|error (не статус платежа!)
 * @property ?\Illuminate\Support\Carbon $last_polled_at
 * @property int $poll_error_streak подряд-ошибки опроса; успешный опрос сбрасывает в 0
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
        'lead_id',
        'provider',
        'purpose',
        'amount_cents',
        'currency',
        'status',
        'external_ref',
        'external_id',
        'raw_payload',
        'paid_at',
        'last_poll_result',
        'last_polled_at',
        'poll_error_streak',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'member_id' => 'integer',
        'amount_cents' => 'integer',
        'raw_payload' => 'array',
        'paid_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'poll_error_streak' => 'integer',
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
