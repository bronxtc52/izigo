<?php

namespace Modules\Calculator\Services\Notification;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\NotificationInbox;
use Modules\Calculator\Models\NotificationOutbox;

/**
 * C1 (Block C) — СТАБИЛЬНЫЙ КОНТРАКТ центра уведомлений (backbone). C2/C7 и любые
 * события зовут ТОЛЬКО этот сервис, не трогая outbox/inbox напрямую.
 *
 * Семантика:
 *  - inbox (если inbox=true) + outbox пишутся в ОДНОЙ транзакции (атомарно, чанк целиком);
 *  - идемпотентность по dedup_key: повтор с тем же ключом НЕ создаёт дубликат
 *    (ни в outbox, ни в inbox — у обеих таблиц свой unique(dedup_key), insertOrIgnore
 *    молча пропускает уже поставленные; см. миграцию 2026_07_02_010000);
 *  - chat_id берётся снимком из Member.telegram_id на момент постановки;
 *  - body — это уже готовый Telegram-HTML (нормализацию делает вызывающий код).
 *
 * @see docs/specs/2026-06-22-block-c-gate-a.md (п.2 контракт)
 */
class NotificationService
{
    /**
     * Поставить уведомление одному участнику.
     *
     * @param array<string,mixed>|null $data
     */
    public function enqueueToMember(
        int $memberId,
        string $kind,
        string $html,
        ?string $title = null,
        ?string $dedupKey = null,
        ?array $data = null,
        bool $inbox = true,
    ): void {
        $this->enqueueForMembers([$memberId], $kind, $html, $title, $dedupKey, $data, $inbox);
    }

    /**
     * Поставить уведомление пачке участников. dedupKey, если задан, относится к КАЖДОЙ
     * записи отдельно: чтобы он остался уникальным на участника, к нему добавляется
     * суффикс member_id (иначе вторая запись пачки упала бы на unique-ключе).
     *
     * Bulk-постановка (B6): вместо 3 запросов и транзакции на каждого получателя — чанки
     * по 500 строк, insertOrIgnore в outbox и inbox (idempotency-ключи отсеивают уже
     * поставленных, повтор «допоставляет» только недостающих). Чанк атомарен.
     *
     * @param array<int,int>          $memberIds
     * @param array<string,mixed>|null $data
     * @return int сколько НОВЫХ outbox-записей реально поставлено (без дублей)
     */
    public function enqueueForMembers(
        array $memberIds,
        string $kind,
        string $html,
        ?string $title = null,
        ?string $dedupKey = null,
        ?array $data = null,
        bool $inbox = true,
        ?int $broadcastId = null,
    ): int {
        $memberIds = array_values(array_unique(array_filter($memberIds, static fn ($id) => (int) $id > 0)));
        if ($memberIds === []) {
            return 0;
        }
        $total = count($memberIds);

        // Снимок telegram_id (chat_id) одним запросом.
        $chatIds = Member::query()
            ->whereIn('id', $memberIds)
            ->pluck('telegram_id', 'id');

        $encodedData = $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
        $inserted = 0;

        foreach (array_chunk($memberIds, 500) as $chunk) {
            $now = now();
            $outboxRows = [];
            $inboxRows = [];
            foreach ($chunk as $memberId) {
                $memberId = (int) $memberId;
                $perMemberDedup = $dedupKey !== null
                    ? $this->scopedDedupKey($dedupKey, $memberId, $total)
                    : null;

                $outboxRows[] = [
                    'member_id' => $memberId,
                    'channel' => 'telegram',
                    'chat_id' => $chatIds->get($memberId) !== null ? (int) $chatIds->get($memberId) : null,
                    'kind' => $kind,
                    'title' => $title,
                    'body' => $html,
                    'data' => $encodedData,
                    'dedup_key' => $perMemberDedup,
                    'status' => NotificationOutbox::STATUS_PENDING,
                    'attempts' => 0,
                    'available_at' => $now,
                    'broadcast_id' => $broadcastId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($inbox) {
                    $inboxRows[] = [
                        'member_id' => $memberId,
                        'kind' => $kind,
                        'title' => $title ?? '',
                        'body' => $html,
                        'data' => $encodedData,
                        'dedup_key' => $perMemberDedup,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Обе фазы чанка в одной транзакции; ON CONFLICT DO NOTHING по unique(dedup_key)
            // каждой таблицы делает повтор идемпотентным без предварительных SELECT'ов.
            $inserted += (int) DB::transaction(function () use ($outboxRows, $inboxRows) {
                $n = NotificationOutbox::query()->insertOrIgnore($outboxRows);
                if ($inboxRows !== []) {
                    NotificationInbox::query()->insertOrIgnore($inboxRows);
                }

                return $n;
            });
        }

        return $inserted;
    }

    /**
     * Для одиночной постановки dedup_key используется как есть (вызывающий уже сделал
     * его уникальным, напр. 'payout.status:wd:5:paid'); для пачки добавляем :m<id>,
     * чтобы unique-ключ не коллизился между участниками.
     */
    private function scopedDedupKey(string $dedupKey, int $memberId, int $count): string
    {
        if ($count <= 1) {
            return $dedupKey;
        }

        return $dedupKey . ':m' . $memberId;
    }
}
