<?php

namespace Modules\Calculator\Services\Notification;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\NotificationBroadcast;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\Telegram\TelegramNotifications;

/**
 * C1 (Block C) — рассылки (broadcasts). Доступны owner+support (RBAC на роутах).
 * preview — dry-run охвата без записи; dispatch — нормализует текст, резолвит сегмент
 * и ставит уведомления пачкой через NotificationService (фон = планировщик).
 */
class BroadcastService
{
    public function __construct(
        private readonly SegmentResolver $segments,
        private readonly NotificationService $notifications,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Dry-run: только охват сегмента, БЕЗ записи в БД.
     *
     * @return array{segment_type:string,segment_value:?string,recipients_count:int}
     */
    public function preview(string $segmentType, ?string $segmentValue = null): array
    {
        return [
            'segment_type' => $segmentType,
            'segment_value' => $segmentValue,
            'recipients_count' => $this->segments->count($segmentType, $segmentValue),
        ];
    }

    /**
     * Отправка рассылки: создаёт broadcast-запись, нормализует текст в Telegram-HTML,
     * ставит уведомления резолвленному сегменту пачкой (chunk). Сырьё хранится в body_raw.
     *
     * @return array{broadcast_id:int,recipients_count:int,status:string}
     */
    public function dispatch(int $actorMemberId, string $segmentType, ?string $segmentValue, string $bodyRaw): array
    {
        $bodyRaw = trim($bodyRaw);
        if ($bodyRaw === '') {
            throw new InvalidArgumentException('Текст рассылки не может быть пустым');
        }

        // Резолвим сегмент заранее (валидация типа/значения здесь же бросит при ошибке).
        $memberIds = $this->segments->resolve($segmentType, $segmentValue);
        $html = TelegramNotifications::mdToTelegramHtml($bodyRaw);

        $broadcast = NotificationBroadcast::query()->create([
            'actor_member_id' => $actorMemberId,
            'segment_type' => $segmentType,
            'segment_value' => $segmentValue,
            'body_raw' => $bodyRaw, // СЫРЬЁ
            'recipients_count' => count($memberIds),
            'status' => NotificationBroadcast::STATUS_PROCESSING,
            'created_at' => now(),
            'queued_at' => now(),
        ]);

        // Ставим пачками, чтобы не держать большой набор id в памяти и не делать
        // один гигантский insert. Фон (диспетчер) уже сам растягивает доставку по тикам.
        foreach (array_chunk($memberIds, 500) as $chunk) {
            $this->notifications->enqueueForMembers(
                $chunk,
                'broadcast',
                $html,
                null,
                'broadcast:' . $broadcast->id,
                null,
                true,
                $broadcast->id,
            );
        }

        $broadcast->status = NotificationBroadcast::STATUS_DONE;
        $broadcast->save();

        $this->audit->recordSafe(
            $actorMemberId,
            'broadcast.send',
            'broadcast',
            $broadcast->id,
            null,
            [
                'segment_type' => $segmentType,
                'segment_value' => $segmentValue,
                'recipients_count' => count($memberIds),
            ],
        );

        return [
            'broadcast_id' => $broadcast->id,
            'recipients_count' => count($memberIds),
            'status' => $broadcast->status,
        ];
    }
}
