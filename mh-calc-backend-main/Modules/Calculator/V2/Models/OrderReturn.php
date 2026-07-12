<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * T12: заголовок возврата заказа (v2_order_returns). Оркеструет reversal-chain;
 * шаги — в v2_reversal_actions, корректировки закрытых периодов — в
 * v2_period_corrections. Деньги integer USD-центы, PV decimal(18,6).
 *
 * @property int $id
 * @property int $order_id
 * @property int $member_id
 * @property string $kind        full|partial
 * @property string $status      draft|reversing|reversed|needs_manual|failed
 * @property string $reason
 * @property int $returned_bv_cents
 * @property string $returned_pv
 * @property int $policy_version_id
 * @property ?int $created_by_admin_id
 * @property string $idempotency_key
 */
class OrderReturn extends Model
{
    public const KIND_FULL = 'full';
    public const KIND_PARTIAL = 'partial';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVERSING = 'reversing';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_NEEDS_MANUAL = 'needs_manual';
    public const STATUS_FAILED = 'failed';

    protected $table = 'v2_order_returns';

    protected $fillable = [
        'order_id',
        'member_id',
        'kind',
        'status',
        'reason',
        'returned_bv_cents',
        'returned_pv',
        'policy_version_id',
        'created_by_admin_id',
        'idempotency_key',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'member_id' => 'integer',
        'returned_bv_cents' => 'integer',
        'returned_pv' => 'string',
        'policy_version_id' => 'integer',
        'created_by_admin_id' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderReturnLine::class, 'return_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ReversalAction::class, 'return_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PeriodCorrection::class, 'return_id');
    }
}
