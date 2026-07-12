<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * T03: результат прогона матчинга min(free L, free R) участника.
 * matched_bv_usd_cents = min(BV потреблённого слева, BV потреблённого справа) —
 * BV фактически потреблённых лотов (DEC-016), единственный денежный вход T06.
 * Идемпотентность — UNIQUE(member_id, period_key, run_uuid).
 *
 * @property int $id
 * @property int $member_id
 * @property ?string $period_key 'YYYY-MM-H1'|'YYYY-MM-H2'
 * @property string $run_uuid
 * @property string $matched_pv
 * @property int $matched_bv_usd_cents
 * @property string $status provisional|final|reversed
 */
class BinaryMatch extends Model
{
    public const STATUS_PROVISIONAL = 'provisional';
    public const STATUS_FINAL = 'final';
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'v2_binary_matches';

    protected $fillable = [
        'member_id',
        'period_key',
        'run_uuid',
        'cutoff_at',
        'matched_pv',
        'matched_bv_usd_cents',
        'status',
        'reversal_required_at',
        'reversal_reason',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'matched_pv' => 'string',
        'matched_bv_usd_cents' => 'integer',
        'cutoff_at' => 'datetime',
        'reversal_required_at' => 'datetime',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(PvLotAllocation::class, 'binary_match_id');
    }
}
