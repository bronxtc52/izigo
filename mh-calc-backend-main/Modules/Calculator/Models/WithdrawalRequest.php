<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Заявка на вывод средств. Сумма — целые центы.
 * Статусы: requested|approved|paid|rejected|cancelled.
 *
 * @property int $id
 * @property int $member_id
 * @property int $amount_cents
 * @property string $payout_details
 * @property string $status
 * @property ?int $decided_by
 * @property ?string $reject_reason
 */
class WithdrawalRequest extends Model
{
    public $timestamps = false;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PAID = 'paid';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'member_id',
        'amount_cents',
        'payout_details',
        'status',
        'requested_at',
        'decided_by',
        'decided_at',
        'paid_at',
        'reject_reason',
        'idempotency_key',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
