<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * C2 (Block C): тикет поддержки. Заводит партнёр (member_id), отвечает оператор
 * (owner/support). Без priority/вложений. status — статус-машина обращения.
 *
 * @property int $id
 * @property int $member_id
 * @property string $subject
 * @property string $status
 * @property ?int $assigned_to
 * @property ?\Illuminate\Support\Carbon $last_message_at
 */
class Ticket extends Model
{
    protected $table = 'tickets';

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'member_id',
        'subject',
        'status',
        'assigned_to',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id');
    }
}
