<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * T11: построчный вклад в 60%-калибровку (v2_pool_calibration_items). bonus_kind ∈
 * {structure, global}; calibrated_cents = после factor_bps; retained_cents = amount −
 * calibrated (удержано компанией). Реферальная сюда НЕ пишется (вне пула, MF-W3-3).
 *
 * @property int $id
 * @property int $calibration_id
 * @property string $bonus_kind
 * @property ?int $member_id
 * @property ?int $source_ref
 * @property int $amount_after_caps_cents
 * @property int $calibrated_cents
 * @property int $retained_cents
 * @property string $state
 */
class PoolCalibrationItem extends Model
{
    public const KIND_STRUCTURE = 'structure';
    public const KIND_GLOBAL = 'global';

    /** Фактическую проводку делает T02 NsToOsTransfer (структурная НС→ОС). */
    public const STATE_PROJECTED = 'projected';
    /** final_cents аллокации уже записан (глобальная). */
    public const STATE_APPLIED = 'applied';

    protected $table = 'v2_pool_calibration_items';

    protected $fillable = [
        'calibration_id',
        'bonus_kind',
        'member_id',
        'source_ref',
        'amount_after_caps_cents',
        'calibrated_cents',
        'retained_cents',
        'state',
    ];

    protected $casts = [
        'calibration_id' => 'integer',
        'member_id' => 'integer',
        'source_ref' => 'integer',
        'amount_after_caps_cents' => 'integer',
        'calibrated_cents' => 'integer',
        'retained_cents' => 'integer',
    ];

    public function calibration(): BelongsTo
    {
        return $this->belongsTo(PoolCalibration::class, 'calibration_id');
    }
}
