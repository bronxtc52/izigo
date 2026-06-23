<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Входящее уведомление партнёра (C1, Block C), показывается в приложении (Mini App).
 * Записывается NotificationService в одной транзакции с outbox при inbox=true.
 *
 * @property int $id
 * @property int $member_id
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property ?array $data
 * @property ?\Illuminate\Support\Carbon $read_at
 */
class NotificationInbox extends Model
{
    protected $table = 'notification_inbox';

    protected $fillable = [
        'member_id',
        'kind',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];
}
