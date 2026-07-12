<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * T11: заголовок 60%-калибровки месяца (v2_pool_calibrations). Ровно одна committed
 * на месяц (partial unique index) — её factor_bps читают T08 (лидерский) и T04
 * (перевод НС→ОС). Прежние прогоны — superseded (BR-POOL-002).
 *
 * @property int $id
 * @property int $period_id
 * @property string $month
 * @property int $run_version
 * @property ?int $policy_version_id
 * @property int $pool_rate_bps
 * @property int $base_bv_cents
 * @property int $pool_cap_cents
 * @property int $structure_after_caps_cents
 * @property int $global_after_caps_cents
 * @property int $referral_gross_cents
 * @property int $total_after_caps_cents
 * @property int $factor_bps
 * @property int $scaled_total_cents
 * @property int $company_retained_cents
 * @property string $status
 * @property ?string $created_by
 * @property ?\Illuminate\Support\Carbon $committed_at
 */
class PoolCalibration extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'v2_pool_calibrations';

    protected $fillable = [
        'period_id',
        'month',
        'run_version',
        'policy_version_id',
        'pool_rate_bps',
        'base_bv_cents',
        'pool_cap_cents',
        'structure_after_caps_cents',
        'global_after_caps_cents',
        'referral_gross_cents',
        'total_after_caps_cents',
        'factor_bps',
        'scaled_total_cents',
        'company_retained_cents',
        'status',
        'created_by',
        'committed_at',
    ];

    protected $casts = [
        'period_id' => 'integer',
        'run_version' => 'integer',
        'policy_version_id' => 'integer',
        'pool_rate_bps' => 'integer',
        'base_bv_cents' => 'integer',
        'pool_cap_cents' => 'integer',
        'structure_after_caps_cents' => 'integer',
        'global_after_caps_cents' => 'integer',
        'referral_gross_cents' => 'integer',
        'total_after_caps_cents' => 'integer',
        'factor_bps' => 'integer',
        'scaled_total_cents' => 'integer',
        'company_retained_cents' => 'integer',
        'committed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PoolCalibrationItem::class, 'calibration_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CalcPeriod::class, 'period_id');
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }
}
