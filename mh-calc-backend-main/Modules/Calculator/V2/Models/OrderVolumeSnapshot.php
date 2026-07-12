<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T03: immutable BV/PV-снапшот позиции заказа на момент PAID (DEC-003).
 * Строка создаётся один раз (unique по order_item_id) и НИКОГДА не обновляется —
 * смена цены/PV товара после оплаты снапшот не меняет.
 *
 * @property int $id
 * @property int $order_id
 * @property int $order_item_id
 * @property int $member_id
 * @property string $pv decimal(18,6)
 * @property int $bv_usd_cents
 * @property int $policy_version_id
 */
class OrderVolumeSnapshot extends Model
{
    protected $table = 'v2_order_volume_snapshots';

    public $timestamps = false; // только created_at, ставится при insert; update-пути нет

    protected $fillable = [
        'order_id',
        'order_item_id',
        'member_id',
        'pv',
        'bv_usd_cents',
        'policy_version_id',
        'paid_at',
        'created_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'member_id' => 'integer',
        'pv' => 'string',
        'bv_usd_cents' => 'integer',
        'policy_version_id' => 'integer',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
