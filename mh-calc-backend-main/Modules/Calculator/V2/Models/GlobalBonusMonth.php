<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * T09: immutable-снапшот месяца глобального бонуса (v2_global_bonus_months, DEC-036).
 * status draft→final; финальный месяц пересчёту не подлежит.
 *
 * @property int $id
 * @property int $month_period_id
 * @property int $policy_version_id
 * @property int $global_bv_cents
 * @property string $status
 * @property ?\Carbon\CarbonImmutable $computed_at
 * @property ?\Carbon\CarbonImmutable $finalized_at
 */
class GlobalBonusMonth extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINAL = 'final';

    protected $table = 'v2_global_bonus_months';

    protected $fillable = [
        'month_period_id',
        'policy_version_id',
        'global_bv_cents',
        'status',
        'computed_at',
        'finalized_at',
        'meta',
    ];

    protected $casts = [
        'month_period_id' => 'integer',
        'policy_version_id' => 'integer',
        'global_bv_cents' => 'integer',
        'computed_at' => 'immutable_datetime',
        'finalized_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    public function isFinal(): bool
    {
        return $this->status === self::STATUS_FINAL;
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CalcPeriod::class, 'month_period_id');
    }

    public function pools(): HasMany
    {
        return $this->hasMany(GlobalBonusPool::class, 'global_bonus_month_id');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(GlobalBonusQualification::class, 'global_bonus_month_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GlobalBonusAllocation::class, 'global_bonus_month_id');
    }
}
