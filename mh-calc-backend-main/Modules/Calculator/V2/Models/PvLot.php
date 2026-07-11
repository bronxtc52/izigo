<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T03: PV-лот бинара — сколько свободного PV принёс покупатель на сторону side
 * бинарного предка owner_member_id (DEC-055: все binary descendants, включая
 * spillover). PV — decimal(18,6) строками (bcmath), деньги — integer USD-центы.
 * Инвариант: pv_available + pv_matched + pv_reversed = pv_original (CHECK в БД).
 *
 * @property int $id
 * @property int $owner_member_id
 * @property string $side left|right
 * @property int $buyer_member_id
 * @property int $origin_order_id
 * @property int $origin_order_item_id
 * @property string $pv_original
 * @property string $pv_available
 * @property string $pv_matched
 * @property string $pv_reversed
 * @property int $bv_usd_cents_original
 * @property int $policy_version_id
 * @property string $state free|grace_held|exhausted|reversed
 * @property ?int $reversal_of_lot_id
 */
class PvLot extends Model
{
    public const STATE_FREE = 'free';
    public const STATE_GRACE_HELD = 'grace_held'; // задел T05, логики в T03 нет
    public const STATE_EXHAUSTED = 'exhausted';
    public const STATE_REVERSED = 'reversed';

    public const SIDE_LEFT = 'left';
    public const SIDE_RIGHT = 'right';

    protected $table = 'v2_pv_lots';

    protected $fillable = [
        'owner_member_id',
        'side',
        'buyer_member_id',
        'origin_order_id',
        'origin_order_item_id',
        'pv_original',
        'pv_available',
        'pv_matched',
        'pv_reversed',
        'bv_usd_cents_original',
        'policy_version_id',
        'state',
        'reversal_of_lot_id',
        'occurred_at',
    ];

    protected $casts = [
        'owner_member_id' => 'integer',
        'buyer_member_id' => 'integer',
        'origin_order_id' => 'integer',
        'origin_order_item_id' => 'integer',
        'pv_original' => 'string',
        'pv_available' => 'string',
        'pv_matched' => 'string',
        'pv_reversed' => 'string',
        'bv_usd_cents_original' => 'integer',
        'policy_version_id' => 'integer',
        'reversal_of_lot_id' => 'integer',
        'occurred_at' => 'datetime',
    ];
}
