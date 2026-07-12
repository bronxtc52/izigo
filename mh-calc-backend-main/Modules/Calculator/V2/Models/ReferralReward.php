<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T07: строка реферальной премии (v2_referral_rewards). Провенанс уровня заказа×глубины:
 * тир получателя, ставка, база BV, gross; net заполняет T11, reversed_* — T12.
 * Деньги — integer USD-центы; ставки — integer basis points (casts на integer).
 *
 * @property int $id
 * @property int $order_id
 * @property int $source_member_id       покупатель
 * @property int $beneficiary_member_id  получатель премии
 * @property int $depth                  1 | 2
 * @property ?string $tier_snapshot      START|BUSINESS|ELITE|null
 * @property int $rate_bps
 * @property int $base_bv_cents
 * @property int $gross_cents
 * @property ?int $net_cents             T11 (null = не калибровано)
 * @property string $status              posted|zero_rate|blocked_elite
 * @property int $policy_version_id
 * @property \Illuminate\Support\Carbon $paid_at
 * @property ?string $ledger_idempotency_key
 * @property array $explain
 * @property ?\Illuminate\Support\Carbon $reversed_at
 * @property ?string $reversal_reason
 */
class ReferralReward extends Model
{
    protected $table = 'v2_referral_rewards';

    public const STATUS_POSTED = 'posted';
    public const STATUS_ZERO_RATE = 'zero_rate';
    public const STATUS_BLOCKED_ELITE = 'blocked_elite';

    protected $fillable = [
        'order_id',
        'source_member_id',
        'beneficiary_member_id',
        'depth',
        'tier_snapshot',
        'rate_bps',
        'base_bv_cents',
        'gross_cents',
        'net_cents',
        'status',
        'policy_version_id',
        'paid_at',
        'ledger_idempotency_key',
        'explain',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'source_member_id' => 'integer',
        'beneficiary_member_id' => 'integer',
        'depth' => 'integer',
        'rate_bps' => 'integer',
        'base_bv_cents' => 'integer',
        'gross_cents' => 'integer',
        'net_cents' => 'integer',
        'policy_version_id' => 'integer',
        'paid_at' => 'datetime',
        'explain' => 'array',
        'reversed_at' => 'datetime',
    ];
}
