<?php

namespace Modules\Calculator\Models\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * mh-full-plan T15: сводка паритетного прогона V1 vs V2 (v2_parity_runs).
 * accept возможен только при unexplained_delta_cents == 0 (гейт отчёта).
 */
class ParityRun extends Model
{
    protected $table = 'v2_parity_runs';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status', 'scope', 'v1_total_cents', 'v2_total_cents',
        'unexplained_delta_cents', 'summary',
        'accepted_at', 'accepted_by', 'created_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'summary' => 'array',
        'v1_total_cents' => 'integer',
        'v2_total_cents' => 'integer',
        'unexplained_delta_cents' => 'integer',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function diffs(): HasMany
    {
        return $this->hasMany(ParityDiff::class, 'run_id');
    }

    /** Отчёт можно принять (owner sign-off), только если нет необъяснённых расхождений. */
    public function isAcceptable(): bool
    {
        return $this->status === self::STATUS_DONE && (int) $this->unexplained_delta_cents === 0;
    }
}
