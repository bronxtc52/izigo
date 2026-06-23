<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Исходящее уведомление (C1, Block C). Фон = планировщик: диспетчер
 * `notifications:outbox-dispatch` шлёт pending записи через TelegramNotifier.
 * body — готовый Telegram-HTML; dedup_key — идемпотентность enqueue.
 *
 * @property int $id
 * @property int $member_id
 * @property string $channel
 * @property ?int $chat_id
 * @property string $kind
 * @property ?string $title
 * @property string $body
 * @property ?array $data
 * @property ?string $dedup_key
 * @property string $status
 * @property int $attempts
 * @property int $max_attempts
 * @property ?\Illuminate\Support\Carbon $available_at
 * @property ?string $last_error
 * @property ?int $broadcast_id
 * @property ?\Illuminate\Support\Carbon $sent_at
 */
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'member_id',
        'channel',
        'chat_id',
        'kind',
        'title',
        'body',
        'data',
        'dedup_key',
        'status',
        'attempts',
        'max_attempts',
        'available_at',
        'last_error',
        'broadcast_id',
        'sent_at',
    ];

    protected $casts = [
        'data' => 'array',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'chat_id' => 'integer',
    ];
}
