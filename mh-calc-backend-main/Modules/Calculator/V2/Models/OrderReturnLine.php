<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T12: строка возврата (v2_order_return_lines) — по позиции заказа. returned_pv /
 * returned_bv_cents — иммутабельный снапшот из OrderItem (DEC-003); пропорция
 * частичного возврата считается строго по нему.
 *
 * @property int $id
 * @property int $return_id
 * @property int $order_item_id
 * @property int $qty
 * @property string $returned_pv
 * @property int $returned_bv_cents
 */
class OrderReturnLine extends Model
{
    protected $table = 'v2_order_return_lines';

    protected $fillable = [
        'return_id',
        'order_item_id',
        'qty',
        'returned_pv',
        'returned_bv_cents',
    ];

    protected $casts = [
        'return_id' => 'integer',
        'order_item_id' => 'integer',
        'qty' => 'integer',
        'returned_pv' => 'string',
        'returned_bv_cents' => 'integer',
    ];
}
