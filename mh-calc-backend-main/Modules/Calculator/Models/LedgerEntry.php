<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Иммутабельная проводка журнала двойной записи. Суммы — целые центы.
 *
 * @property int $id
 * @property string $tx_id
 * @property ?int $member_id
 * @property string $account_type
 * @property string $direction
 * @property int $amount_cents
 * @property string $source_type
 * @property ?int $source_id
 * @property ?string $idempotency_key
 * @property ?array $meta
 */
class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tx_id',
        'member_id',
        'account_type',
        'direction',
        'amount_cents',
        'source_type',
        'source_id',
        'idempotency_key',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
