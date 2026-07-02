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
     * ставит уведомления резолвленному сегменту пачкой (bulk в NotificationService).
     * Сырьё хранится в body_raw.
     *
     * Идемпотентность (B6): dedup-ключ детерминирован по СОДЕРЖИМОМУ (сегмент+текст),
     * а не по id новой записи — повторная отправка того же текста тому же сегменту
     * (двойной клик, ретрай после таймаута) не задваивает доставку. Осознанный трейд-офф:
     * намеренный повтор идентичной рассылки требует изменить текст.
     *
     * @return array{broadcast_id:int,recipients_count:int,enqueued:int,status:string}
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

        $enqueued = $this->notifications->enqueueForMembers(
            $memberIds,
            'broadcast',
            $html,
            null,
            self::contentKey($segmentType, $segmentValue, $bodyRaw),
            null,
            true,
            $broadcast->id,
        );

        // DONE = «постановка в outbox завершена» (доставку растягивает диспетчер,
        // её прогресс виден в мониторинге C7 по outbox-статусам).
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
                'enqueued' => $enqueued,
            ],
        );

        return [
            'broadcast_id' => $broadcast->id,
            'recipients_count' => count($memberIds),
            'enqueued' => $enqueued,
            'status' => $broadcast->status,
        ];
    }

    /**
     * Допоставка застрявшей рассылки (упали между постановкой чанков: статус так и остался
     * processing). Повторяет enqueue тем же контент-ключом — insertOrIgnore достроит только
     * недостающих, уже поставленным дублей не будет.
     *
     * @return array{broadcast_id:int,recipients_count:int,enqueued:int,status:string}
     */
    public function resume(int $actorMemberId, int $broadcastId): array
    {
        $broadcast = NotificationBroadcast::query()->findOrFail($broadcastId); // 404 через guarded
        if ($broadcast->status !== NotificationBroadcast::STATUS_PROCESSING) {
            throw new InvalidArgumentException('Допоставить можно только зависшую processing-рассылку');
        }

        $memberIds = $this->segments->resolve($broadcast->segment_type, $broadcast->segment_value);
        $html = TelegramNotifications::mdToTelegramHtml((string) $broadcast->body_raw);

        $enqueued = $this->notifications->enqueueForMembers(
            $memberIds,
            'broadcast',
            $html,
            null,
            self::contentKey((string) $broadcast->segment_type, $broadcast->segment_value, (string) $broadcast->body_raw),
            null,
            true,
            $broadcast->id,
        );

        $broadcast->status = NotificationBroadcast::STATUS_DONE;
        $broadcast->save();

        $this->audit->recordSafe($actorMemberId, 'broadcast.resume', 'broadcast', $broadcast->id, null, [
            'enqueued_missing' => $enqueued,
        ]);

        return [
            'broadcast_id' => $broadcast->id,
            'recipients_count' => count($memberIds),
            'enqueued' => $enqueued,
            'status' => $broadcast->status,
        ];
    }

    /**
     * Детерминированный базовый dedup-ключ рассылки: канонизированный сегмент + сырой текст.
     * Сегмент сериализуется фиксированной структурой (порядок ключей задан здесь, не входом),
     * версия v1 в префиксе — на случай смены схемы ключа.
     */
    private static function contentKey(string $segmentType, ?string $segmentValue, string $bodyRaw): string
    {
        $canonicalSegment = json_encode(
            ['segment_type' => $segmentType, 'segment_value' => $segmentValue],
            JSON_UNESCAPED_UNICODE,
        );

        return 'broadcast:v1:' . sha1($canonicalSegment . "\n" . $bodyRaw);
    }
}
