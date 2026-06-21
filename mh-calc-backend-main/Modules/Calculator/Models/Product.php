<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Товар каталога (Фаза 4, модель A). Цена — целые USDT-центы. package_id — тариф,
 * который активируется при оплате заказа с этим товаром.
 *
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property int $price_usdt_cents
 * @property int $pv
 * @property int $package_id
 * @property string $sku
 * @property bool $is_active
 * @property int $sort
 * @property ?int $stock
 */
class Product extends Model
{
    protected $fillable = [
        'name',
        'description',
        'price_usdt_cents',
        'pv',
        'package_id',
        'sku',
        'is_active',
        'sort',
        'stock',
    ];

    protected $casts = [
        'price_usdt_cents' => 'integer',
        'pv' => 'integer',
        'package_id' => 'integer',
        'is_active' => 'boolean',
        'sort' => 'integer',
        'stock' => 'integer',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }
}
