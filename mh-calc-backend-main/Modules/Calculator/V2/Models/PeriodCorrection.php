<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T12: корректирующая проводка закрытого периода (v2_period_corrections). DEC-027:
 * закрытый период не переоткрывается — эффект возврата оформляется отдельной
 * проводкой. status proposed→approved→posted|rejected. amount_cents SIGNED.
 *
 * @property int $id
 * @property int $period_id
 * @property ?int $return_id
 * @property int $member_id
 * @property string $bonus_type
 * @property int $amount_cents
 * @property string $status
 * @property string $reason
 * @property ?array $snapshot_json
 * @property ?int $approved_by_admin_id
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?string $ledger_tx_id
 * @property string $idempotency_key
 */
class PeriodCorrection extends Model
{
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'v2_period_corrections';

    protected $fillable = [
        'period_id',
        'return_id',
        'member_id',
        'bonus_type',
        'amount_cents',
        'status',
        'reason',
        'snapshot_json',
        'approved_by_admin_id',
        'approved_at',
        'ledger_tx_id',
        'idempotency_key',
    ];

    protected $casts = [
        'period_id' => 'integer',
        'return_id' => 'integer',
        'member_id' => 'integer',
        'amount_cents' => 'integer',
        'snapshot_json' => 'array',
        'approved_by_admin_id' => 'integer',
        'approved_at' => 'datetime',
    ];
}
