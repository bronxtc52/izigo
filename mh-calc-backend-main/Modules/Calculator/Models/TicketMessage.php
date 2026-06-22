<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * C2 (Block C): сообщение треда тикета. author_role различает партнёра и оператора.
 * body — сырой текст автора (экранирование в Telegram-HTML делается только на выходе
 * в уведомлениях, в API отдаётся как есть и экранируется на фронте).
 *
 * @property int $id
 * @property int $ticket_id
 * @property ?int $author_member_id
 * @property string $author_role
 * @property string $body
 * @property ?\Illuminate\Support\Carbon $read_at
 */
class TicketMessage extends Model
{
    protected $table = 'ticket_messages';

    public const ROLE_MEMBER = 'member';
    public const ROLE_OPERATOR = 'operator';

    protected $fillable = [
        'ticket_id',
        'author_member_id',
        'author_role',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }
}
