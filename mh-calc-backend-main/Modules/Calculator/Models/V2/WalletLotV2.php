<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;

/**
 * mh-full-plan T02: кредит-лот ОС/БС (v2_wallet_lots). expires_at NULL = не сгорает
 * (award-лоты, MF-9). Порядок потребления — EARLIEST_EXPIRY_FIRST, при равенстве id ASC
 * (DEC-015); лоты без срока потребляются последними.
 */
class WalletLotV2 extends Model
{
    protected $table = 'v2_wallet_lots';

    public const ACCOUNT_OS = 'os';
    public const ACCOUNT_BS = 'bs';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_TRANSFERRED = 'transferred'; // остаток ОС ушёл в БС при сгорании
    public const STATUS_EXPIRED = 'expired';         // остаток БС аннулирован

    protected $fillable = [
        'member_id', 'account', 'amount_cents', 'available_cents',
        'earned_at', 'expires_at', 'source_type', 'source_id',
        'origin_lot_id', 'status', 'idempotency_key',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'available_cents' => 'integer',
        'earned_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
