<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * On-chain выплата USDT по заявке на вывод (Фаза 4). Суммы — целые USDT-центы.
 *
 * @property int $id
 * @property int $withdrawal_request_id
 * @property string $to_address
 * @property int $amount_cents
 * @property ?string $tx_hash
 * @property string $status
 */
class PayoutTransaction extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_BROADCAST = 'broadcast';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'withdrawal_request_id',
        'to_address',
        'amount_cents',
        'tx_hash',
        'status',
        'error',
    ];

    protected $casts = [
        'withdrawal_request_id' => 'integer',
        'amount_cents' => 'integer',
    ];

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }
}
