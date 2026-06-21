<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Денормализованный кэш баланса кошелька. Source of truth — ledger_entries.
 * Суммы — целые центы.
 *
 * @property int $id
 * @property int $member_id
 * @property int $available_cents
 * @property int $held_cents
 * @property int $clawback_debt_cents
 * @property string $currency
 */
class MemberWallet extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'member_id',
        'available_cents',
        'held_cents',
        'clawback_debt_cents',
        'currency',
        'updated_at',
    ];

    protected $casts = [
        'available_cents' => 'integer',
        'held_cents' => 'integer',
        'clawback_debt_cents' => 'integer',
        'updated_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
