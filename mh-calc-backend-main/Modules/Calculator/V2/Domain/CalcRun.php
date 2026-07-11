<?php

namespace Modules\Calculator\V2\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * V2 T04: расчётный прогон периода (v2_calc_runs). mode: preview — диагностический
 * прогон без изменения статуса периода; close — боевое закрытие (rerun добавит T12,
 * enum расширяемый). result_hash — sha256 детерминированного результата: два
 * preview на одном снапшоте обязаны давать одинаковый hash (ARCH-NFR-01).
 *
 * @property int $id
 * @property int $period_id
 * @property int $run_no
 * @property string $mode
 * @property string $status
 * @property \Carbon\CarbonImmutable $input_cutoff
 * @property ?int $snapshot_id
 * @property string $engine_version
 * @property ?string $result_hash
 * @property string $idempotency_key
 * @property ?array $step_results
 * @property ?string $error
 */
class CalcRun extends Model
{
    public const MODE_PREVIEW = 'preview';
    public const MODE_CLOSE = 'close';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $table = 'v2_calc_runs';

    protected $fillable = [
        'period_id',
        'run_no',
        'mode',
        'status',
        'input_cutoff',
        'snapshot_id',
        'engine_version',
        'result_hash',
        'idempotency_key',
        'step_results',
        'error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'period_id' => 'integer',
        'run_no' => 'integer',
        'snapshot_id' => 'integer',
        'input_cutoff' => 'immutable_datetime',
        'step_results' => 'array',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(CalcPeriod::class, 'period_id');
    }

    public function snapshot(): HasOne
    {
        return $this->hasOne(CalcSnapshot::class, 'run_id');
    }
}
