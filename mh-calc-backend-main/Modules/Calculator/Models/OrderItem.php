<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Позиция заказа со снимком цены/имени/PV на момент покупки.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int $qty
 * @property int $unit_price_usdt_cents
 * @property int $pv
 * @property string $name_snapshot
 */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'product_id',
        'qty',
        'unit_price_usdt_cents',
        'pv',
        'name_snapshot',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'product_id' => 'integer',
        'qty' => 'integer',
        'unit_price_usdt_cents' => 'integer',
        'pv' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
