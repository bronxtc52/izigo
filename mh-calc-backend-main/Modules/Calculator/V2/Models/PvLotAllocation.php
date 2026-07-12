<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T03: потребление конкретного лота конкретным матчем (provenance, DEC-054;
 * reversal-связка для T12). Сумма bv_usd_cents_consumed по стороне прогона =
 * BV-итог стороны (largest-remainder, ни цента мимо).
 *
 * @property int $id
 * @property int $binary_match_id
 * @property int $pv_lot_id
 * @property string $side
 * @property string $pv_consumed
 * @property int $bv_usd_cents_consumed
 */
class PvLotAllocation extends Model
{
    protected $table = 'v2_pv_lot_allocations';

    public $timestamps = false; // только created_at — строка неизменяемая

    protected $fillable = [
        'binary_match_id',
        'pv_lot_id',
        'side',
        'pv_consumed',
        'bv_usd_cents_consumed',
        'created_at',
    ];

    protected $casts = [
        'binary_match_id' => 'integer',
        'pv_lot_id' => 'integer',
        'pv_consumed' => 'string',
        'bv_usd_cents_consumed' => 'integer',
        'created_at' => 'datetime',
    ];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(PvLot::class, 'pv_lot_id');
    }
}
