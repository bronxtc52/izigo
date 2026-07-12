<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T09: квартальная выплата глобального пула на ОС (v2_global_bonus_payouts).
 * amount_cents = Σ final_cents трёх месяцев по участнику; idempotency_key
 * v2:glb:q:{quarterId}:m:{memberId} — двойной прогон не задваивает.
 *
 * @property int $id
 * @property int $quarter_period_id
 * @property int $member_id
 * @property int $amount_cents
 * @property string $idempotency_key
 * @property ?\Carbon\CarbonImmutable $posted_at
 * @property string $status
 */
class GlobalBonusPayout extends Model
{
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $table = 'v2_global_bonus_payouts';

    protected $fillable = [
        'quarter_period_id',
        'member_id',
        'amount_cents',
        'idempotency_key',
        'posted_at',
        'status',
    ];

    protected $casts = [
        'quarter_period_id' => 'integer',
        'member_id' => 'integer',
        'amount_cents' => 'integer',
        'posted_at' => 'immutable_datetime',
    ];
}
