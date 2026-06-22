<?php

namespace Modules\Calculator\Services\Notification;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\NotificationOutbox;
use Modules\Calculator\Services\Telegram\TelegramNotifier;

/**
 * C1 (Block C) — диспетчер outbox. Вызывается scheduled-командой
 * `notifications:outbox-dispatch` (everyMinute, withoutOverlapping). Фон проекта =
 * планировщик, НЕ Laravel queue.
 *
 * Доставка через TelegramNotifier::deliver(), который различает результат:
 *   sent → sent+sent_at; skipped (нет chat_id/выключено) → skipped;
 *   retry (сеть/429/5xx) → attempts++ + экспоненциальный backoff; при attempts>=max → failed;
 *   failed (4xx: chat not found/bot blocked) → сразу failed (ретрай бессмыслен).
 * Зависшие в `sending` записи (упавший процесс) реанимируются обратно в pending по TTL.
 */
class OutboxDispatcher
{
    /** Записи, застрявшие в sending дольше этого (мин), считаем брошенными и возвращаем в pending. */
    private const SENDING_TTL_MINUTES = 10;

    public function __construct(private readonly TelegramNotifier $notifier)
    {
    }

    /**
     * @return array{processed:int,sent:int,failed:int,skipped:int,retried:int,reaped:int}
     */
    public function dispatch(int $limit = 200): array
    {
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'retried' => 0, 'reaped' => 0];

        // 0) Реанимация зависших в sending записей (процесс упал между переводом и save()).
        $stats['reaped'] = NotificationOutbox::query()
            ->where('status', NotificationOutbox::STATUS_SENDING)
            ->where('updated_at', '<=', now()->subMinutes(self::SENDING_TTL_MINUTES))
            ->update([
                'status' => NotificationOutbox::STATUS_PENDING,
                'available_at' => now(),
                'updated_at' => now(),
            ]);

        // 1) Под блокировкой берём пачку готовых и переводим в sending (skipLocked, чтобы
        // параллельный тик не взял те же; withoutOverlapping страхует дополнительно).
        $rows = DB::transaction(function () use ($limit) {
            $rows = NotificationOutbox::query()
                ->where('status', NotificationOutbox::STATUS_PENDING)
                ->where(function ($q) {
                    $q->whereNull('available_at')->orWhere('available_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                NotificationOutbox::query()->whereIn('id', $rows->pluck('id')->all())
                    ->update(['status' => NotificationOutbox::STATUS_SENDING, 'updated_at' => now()]);
                // Синхронизируем in-memory статус с БД (baseline = sending), иначе
                // последующий save() с целевым статусом pending не будет «грязным»
                // относительно исходного pending и не перезапишет sending в БД.
                $rows->each(function ($r) {
                    $r->status = NotificationOutbox::STATUS_SENDING;
                    $r->syncOriginal();
                });
            }

            return $rows;
        });

        foreach ($rows as $row) {
            $stats['processed']++;

            // Нет chat_id — доставить в Telegram нельзя; skipped (inbox-копия уже есть).
            if (!$row->chat_id || (int) $row->chat_id <= 0) {
                $row->status = NotificationOutbox::STATUS_SKIPPED;
                $row->last_error = 'no chat_id';
                $row->save();
                $stats['skipped']++;
                continue;
            }

            $outcome = $this->notifier->deliver((int) $row->chat_id, $row->body);

            switch ($outcome) {
                case 'sent':
                    $row->status = NotificationOutbox::STATUS_SENT;
                    $row->sent_at = now();
                    $row->last_error = null;
                    $row->save();
                    $stats['sent']++;
                    break;

                case 'skipped':
                    // Доставка выключена/нет токена — не наша запись «протухла»; помечаем
                    // skipped, чтобы не зависала в sending. (Включат флаг — новые пойдут.)
                    $row->status = NotificationOutbox::STATUS_SKIPPED;
                    $row->last_error = 'delivery disabled';
                    $row->save();
                    $stats['skipped']++;
                    break;

                case 'failed':
                    // Терминальная 4xx — ретрай бессмыслен.
                    $row->attempts = (int) $row->attempts + 1;
                    $row->status = NotificationOutbox::STATUS_FAILED;
                    $row->last_error = 'telegram rejected (4xx)';
                    $row->save();
                    $stats['failed']++;
                    break;

                case 'retry':
                default:
                    $row->attempts = (int) $row->attempts + 1;
                    $row->last_error = 'temporary delivery error';
                    if ($row->attempts >= (int) $row->max_attempts) {
                        $row->status = NotificationOutbox::STATUS_FAILED;
                        $stats['failed']++;
                    } else {
                        $row->status = NotificationOutbox::STATUS_PENDING;
                        $row->available_at = $this->backoff($row->attempts);
                        $stats['retried']++;
                    }
                    $row->save();
                    break;
            }
        }

        return $stats;
    }

    /** Экспоненциальный backoff: 1,2,4,8 мин… (cap 30 мин). */
    private function backoff(int $attempts): Carbon
    {
        $minutes = min(2 ** max(0, $attempts - 1), 30);

        return now()->addMinutes($minutes);
    }
}
