<?php

namespace Modules\Calculator\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Рассылка (C1, Block C). Создаётся BroadcastService::dispatch — кто разослал, по
 * какому сегменту, сырой текст, охват и статус. body_raw хранит СЫРЬЁ (markdown);
 * нормализация в Telegram-HTML — на выходе (в outbox).
 *
 * @property int $id
 * @property ?int $actor_member_id
 * @property string $segment_type
 * @property ?string $segment_value
 * @property string $body_raw
 * @property int $recipients_count
 * @property string $status
 */
class NotificationBroadcast extends Model
{
    protected $table = 'notification_broadcasts';

    public const UPDATED_AT = null; // только created_at + queued_at

    public const STATUS_PREVIEW = 'preview';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';

    protected $fillable = [
        'actor_member_id',
        'segment_type',
        'segment_value',
        'body_raw',
        'recipients_count',
        'status',
        'created_at',
        'queued_at',
    ];

    protected $casts = [
        'recipients_count' => 'integer',
        'created_at' => 'datetime',
        'queued_at' => 'datetime',
    ];
}
