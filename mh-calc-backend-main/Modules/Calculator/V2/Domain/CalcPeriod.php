<?php

namespace Modules\Calculator\V2\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * V2 T04: расчётный период (v2_calc_periods, словарь MF-8). Полуоткрытый интервал
 * [starts_at, ends_at) в UTC; статусы open → closing → closed (reopen НЕ существует —
 * только корректирующие проводки T12). Закрытый период неизменяем: все V2-постинги
 * обязаны звать PeriodService::assertOpen() перед проводками.
 *
 * @property int $id
 * @property string $period_type
 * @property string $code
 * @property \Carbon\CarbonImmutable $starts_at
 * @property \Carbon\CarbonImmutable $ends_at
 * @property string $timezone
 * @property string $status
 * @property ?int $policy_version_id
 * @property ?\Carbon\CarbonImmutable $closed_at
 * @property ?string $closed_by
 */
class CalcPeriod extends Model
{
    public const TYPE_HALF_MONTH = 'half_month';
    public const TYPE_MONTH = 'month';
    public const TYPE_QUARTER = 'quarter';

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSING = 'closing';
    public const STATUS_CLOSED = 'closed';

    protected $table = 'v2_calc_periods';

    protected $fillable = [
        'period_type',
        'code',
        'starts_at',
        'ends_at',
        'timezone',
        'status',
        'policy_version_id',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'policy_version_id' => 'integer',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(CalcRun::class, 'period_id')->orderBy('run_no');
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }
}
