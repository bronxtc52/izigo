<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T12: шаг reversal-chain (v2_reversal_actions) = explainability возврата.
 * amount_cents SIGNED (сторно = отрицательное). snapshot_json — ОРИГИНАЛЬНЫЕ
 * rate/tier/rank/scale исходной проводки (CAL-REV-001); ledger_entries не альтерим.
 *
 * @property int $id
 * @property int $return_id
 * @property string $action_type
 * @property ?string $bonus_type
 * @property ?string $target_type
 * @property ?int $target_id
 * @property int $amount_cents
 * @property string $amount_pv
 * @property ?array $snapshot_json
 * @property ?string $ledger_tx_id
 * @property string $status
 * @property string $idempotency_key
 */
class ReversalAction extends Model
{
    public const TYPE_PV_LOT_REVERSAL = 'pv_lot_reversal';
    public const TYPE_MATCH_COMPENSATION = 'match_compensation';
    public const TYPE_BONUS_REVERSAL = 'bonus_reversal';
    public const TYPE_CLAWBACK = 'clawback';
    public const TYPE_TIER_BASIS_ADJUST = 'tier_basis_adjust';
    public const TYPE_QUALIFICATION_NOTE = 'qualification_note';
    public const TYPE_PERIOD_CORRECTION_PROPOSED = 'period_correction_proposed';

    public const STATUS_PENDING = 'pending';
    public const STATUS_POSTED = 'posted';
    public const STATUS_SKIPPED = 'skipped';

    public const BONUS_STRUCTURAL = 'structural';
    public const BONUS_REFERRAL = 'referral';
    public const BONUS_LEADERSHIP = 'leadership';
    public const BONUS_GLOBAL = 'global';

    protected $table = 'v2_reversal_actions';

    protected $fillable = [
        'return_id',
        'action_type',
        'bonus_type',
        'target_type',
        'target_id',
        'amount_cents',
        'amount_pv',
        'snapshot_json',
        'ledger_tx_id',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'return_id' => 'integer',
        'target_id' => 'integer',
        'amount_cents' => 'integer',
        'amount_pv' => 'string',
        'snapshot_json' => 'array',
    ];
}
