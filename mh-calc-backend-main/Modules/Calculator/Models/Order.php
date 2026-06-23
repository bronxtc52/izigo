<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заказ (Фаза 4). Суммы — целые USDT-центы. Под модель A несёт package_id активируемого
 * тарифа. Статусы: pending_payment|paid|processing|shipped|delivered|cancelled|refunded.
 *
 * @property int $id
 * @property int $member_id
 * @property int $package_id
 * @property int $total_usdt_cents
 * @property int $total_pv
 * @property string $status
 * @property ?string $shipping_info
 * @property ?string $tracking_no
 * @property ?int $activation_event_id
 */
class Order extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PAID = 'paid';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'member_id',
        'lead_id',
        'package_id',
        'total_usdt_cents',
        'total_pv',
        'status',
        'shipping_info',
        'tracking_no',
        'activation_event_id',
        'idempotency_key',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'package_id' => 'integer',
        'total_usdt_cents' => 'integer',
        'total_pv' => 'integer',
        'activation_event_id' => 'integer',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
