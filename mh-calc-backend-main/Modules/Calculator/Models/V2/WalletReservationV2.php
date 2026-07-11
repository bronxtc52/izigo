<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T02: резерв субсчетов под заказ (v2_wallet_reservations).
 * Один живой (reserved) резерв на заказ — партиал-unique индекс.
 */
class WalletReservationV2 extends Model
{
    protected $table = 'v2_wallet_reservations';

    public const STATUS_RESERVED = 'reserved';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'order_id', 'member_id', 'os_cents', 'bs_cents',
        'status', 'expires_at', 'idempotency_key',
    ];

    protected $casts = [
        'os_cents' => 'integer',
        'bs_cents' => 'integer',
        'expires_at' => 'datetime',
    ];
}
