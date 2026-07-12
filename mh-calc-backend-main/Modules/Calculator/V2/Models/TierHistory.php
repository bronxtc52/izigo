<?php

namespace Modules\Calculator\V2\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * T05: append-only история тиров контракта (v2_tier_history). Тир не понижается;
 * unique(member_id, tier) — идемпотентность повышения. tierAsOf T07 читает отсюда.
 *
 * @property int $id
 * @property int $member_id
 * @property string $tier
 * @property ?string $tier_before
 * @property string $basis_personal_pv decimal(18,6)
 * @property ?int $source_order_id
 * @property int $policy_version_id
 */
class TierHistory extends Model
{
    protected $table = 'v2_tier_history';

    public $timestamps = false; // только created_at при insert; update-пути нет

    protected $fillable = [
        'member_id',
        'tier',
        'tier_before',
        'basis_personal_pv',
        'source_order_id',
        'policy_version_id',
        'effective_at',
        'created_at',
    ];

    protected $casts = [
        'member_id' => 'integer',
        'basis_personal_pv' => 'string',
        'source_order_id' => 'integer',
        'policy_version_id' => 'integer',
        'effective_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
