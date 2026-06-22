<?php

namespace Modules\Calculator\Services\Notification;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\NotificationInbox;
use Modules\Calculator\Models\NotificationOutbox;

/**
 * C1 (Block C) — СТАБИЛЬНЫЙ КОНТРАКТ центра уведомлений (backbone). C2/C7 и любые
 * события зовут ТОЛЬКО этот сервис, не трогая outbox/inbox напрямую.
 *
 * Семантика:
 *  - inbox (если inbox=true) + outbox пишутся в ОДНОЙ транзакции (атомарно);
 *  - идемпотентность по dedup_key: повтор с тем же ключом НЕ создаёт дубликат
 *    (ни в outbox, ни в inbox);
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
     * @param array<int,int>          $memberIds
     * @param array<string,mixed>|null $data
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
    ): void {
        $memberIds = array_values(array_unique(array_filter($memberIds, static fn ($id) => (int) $id > 0)));
        if ($memberIds === []) {
            return;
        }

        // Снимок telegram_id (chat_id) одним запросом.
        $chatIds = Member::query()
            ->whereIn('id', $memberIds)
            ->pluck('telegram_id', 'id');

        foreach ($memberIds as $memberId) {
            $memberId = (int) $memberId;
            $perMemberDedup = $dedupKey !== null
                ? $this->scopedDedupKey($dedupKey, $memberId, count($memberIds))
                : null;

            // Идемпотентность (быстрый путь): пропускаем, если запись с этим dedup_key уже
            // есть. На гонку (два параллельных вызова прошли проверку) страхует unique-индекс
            // dedup_key + ловля QueryException ниже — повтор не задвоит ни outbox, ни inbox.
            if ($perMemberDedup !== null
                && NotificationOutbox::query()->where('dedup_key', $perMemberDedup)->exists()) {
                continue;
            }

            try {
                DB::transaction(function () use (
                    $memberId,
                    $kind,
                    $html,
                    $title,
                    $perMemberDedup,
                    $data,
                    $inbox,
                    $broadcastId,
                    $chatIds,
                ) {
                    // Сначала outbox: при гонке именно его unique(dedup_key) бросит и
                    // откатит всю транзакцию ВКЛЮЧАЯ inbox — дубля inbox не будет.
                    NotificationOutbox::query()->create([
                        'member_id' => $memberId,
                        'channel' => 'telegram',
                        'chat_id' => $chatIds->get($memberId) !== null ? (int) $chatIds->get($memberId) : null,
                        'kind' => $kind,
                        'title' => $title,
                        'body' => $html,
                        'data' => $data,
                        'dedup_key' => $perMemberDedup,
                        'status' => NotificationOutbox::STATUS_PENDING,
                        'attempts' => 0,
                        'available_at' => now(),
                        'broadcast_id' => $broadcastId,
                    ]);

                    if ($inbox) {
                        NotificationInbox::query()->create([
                            'member_id' => $memberId,
                            'kind' => $kind,
                            'title' => $title ?? '',
                            'body' => $html,
                            'data' => $data,
                        ]);
                    }
                });
            } catch (QueryException $e) {
                // Нарушение unique(dedup_key) при гонке = «уже поставлено» → идемпотентно
                // пропускаем. Любая другая ошибка БД пробрасывается.
                if ($perMemberDedup !== null && $this->isUniqueViolation($e)) {
                    continue;
                }
                throw $e;
            }
        }
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

    /** Нарушение unique-индекса: Postgres SQLSTATE 23505 (MySQL 1062 — на всякий случай). */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23505' || $code === 1062;
    }
}
