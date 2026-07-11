<?php

namespace Modules\Calculator\V2\Domain;

use Illuminate\Database\Eloquent\Model;

/**
 * V2 T04: журнал исполнений scheduled-джобов по окнам (v2_calc_job_executions).
 * UNIQUE(job_name, window_key) — ядро идемпотентности (DEC-019): повтор
 * succeeded-окна = no-op; running с протухшим lease перехватывается;
 * failed переисполняется с attempts+1 (тот же ряд — без churn).
 *
 * @property int $id
 * @property string $job_name
 * @property string $window_key
 * @property string $status
 * @property int $attempts
 * @property \Carbon\CarbonImmutable $started_at
 * @property ?\Carbon\CarbonImmutable $finished_at
 * @property ?string $error
 */
class CalcJobExecution extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    protected $table = 'v2_calc_job_executions';

    protected $fillable = [
        'job_name',
        'window_key',
        'status',
        'attempts',
        'started_at',
        'finished_at',
        'error',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'started_at' => 'immutable_datetime',
        'finished_at' => 'immutable_datetime',
    ];
}
