<?php

namespace Modules\Calculator\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Calculator\Models\NotificationOutbox;

/**
 * C7 (Block C) — READ-ONLY сводка мониторинга фонового конвейера уведомлений.
 *
 * Контекст (Gate-A R1): у проекта НЕТ async-очереди (QUEUE_CONNECTION=sync, нет
 * queue:work). Фон = scheduled-команды (schedule:work в docker/start.sh). Поэтому
 * главный объект мониторинга — таблица `notification_outbox` (C1) + косвенное
 * здоровье планировщика (тикает ли диспетчер outbox). `failed_jobs` обычно ПУСТ,
 * показываем справочно с пометкой «очередь sync — обычно пусто».
 *
 * Сервис ТОЛЬКО ЧИТАЕТ: ни одной мутации/write. last_error отдаём как есть —
 * C1 пишет туда только обобщённые строки без секретов (см. OutboxDispatcher).
 */
class MonitoringService
{
    /**
     * Порог «застрявших»: записи в pending/sending, готовые к отправке
     * (available_at<=now или null), но не обработанные дольше этого (мин).
     */
    public const STUCK_THRESHOLD_MINUTES = 10;

    /**
     * Полная сводка по outbox: counts по статусам, число застрявших,
     * сигнал здоровья планировщика, справочный failed_jobs.
     *
     * @return array<string,mixed>
     */
    public function outboxSummary(): array
    {
        $now = Carbon::now();
        $stuckBefore = $now->copy()->subMinutes(self::STUCK_THRESHOLD_MINUTES);

        // Counts по каждому статусу (отсутствующие статусы → 0).
        $rawCounts = NotificationOutbox::query()
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $counts = [];
        foreach ([
            NotificationOutbox::STATUS_PENDING,
            NotificationOutbox::STATUS_SENDING,
            NotificationOutbox::STATUS_SENT,
            NotificationOutbox::STATUS_FAILED,
            NotificationOutbox::STATUS_SKIPPED,
        ] as $status) {
            $counts[$status] = (int) ($rawCounts[$status] ?? 0);
        }
        $counts['total'] = array_sum($counts);

        $stuckCount = $this->stuckQuery($stuckBefore)->count();

        return [
            'counts' => $counts,
            'stuck' => [
                'threshold_minutes' => self::STUCK_THRESHOLD_MINUTES,
                'count' => $stuckCount,
            ],
            'scheduler' => $this->schedulerHealth(),
            'failed_jobs' => $this->failedJobsInfo(),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Последние N проблемных записей: failed + застрявшие (read-only).
     * last_error отдаём как есть (C1 туда секреты не кладёт).
     *
     * @return array<int,array<string,mixed>>
     */
    public function problemRecords(int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $stuckBefore = Carbon::now()->subMinutes(self::STUCK_THRESHOLD_MINUTES);

        // failed + застрявшие, новые сверху.
        $rows = NotificationOutbox::query()
            ->where(function ($q) use ($stuckBefore) {
                $q->where('status', NotificationOutbox::STATUS_FAILED)
                    ->orWhere(function ($s) use ($stuckBefore) {
                        $this->applyStuckConditions($s, $stuckBefore);
                    });
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $rows->map(fn (NotificationOutbox $r) => [
            'id' => $r->id,
            'kind' => $r->kind,
            'status' => $r->status,
            'attempts' => (int) $r->attempts,
            'max_attempts' => (int) $r->max_attempts,
            'available_at' => $r->available_at?->toIso8601String(),
            'last_error' => $r->last_error,
            'created_at' => $r->created_at?->toIso8601String(),
            'sent_at' => $r->sent_at?->toIso8601String(),
        ])->all();
    }

    /** Запрос застрявших записей (pending/sending + просрочены + готовы к отправке). */
    private function stuckQuery(Carbon $stuckBefore)
    {
        return NotificationOutbox::query()->where(function ($q) use ($stuckBefore) {
            $this->applyStuckConditions($q, $stuckBefore);
        });
    }

    /**
     * «Застрявшая» = pending или sending, готова к отправке (available_at<=now или null),
     * и не двигалась дольше порога (updated_at<=threshold).
     */
    private function applyStuckConditions($q, Carbon $stuckBefore): void
    {
        $now = Carbon::now();
        $q->whereIn('status', [NotificationOutbox::STATUS_PENDING, NotificationOutbox::STATUS_SENDING])
            ->where(function ($a) use ($now) {
                $a->whereNull('available_at')->orWhere('available_at', '<=', $now);
            })
            ->where('updated_at', '<=', $stuckBefore);
    }

    /**
     * Косвенное здоровье планировщика: время последней обработанной outbox-записи
     * (sent/failed/skipped по updated_at). Если диспетчер тикает — оно свежее.
     * Нет обработанных записей → unknown (фон ещё ничего не слал — это норма).
     *
     * @return array<string,mixed>
     */
    private function schedulerHealth(): array
    {
        $lastProcessed = NotificationOutbox::query()
            ->whereIn('status', [
                NotificationOutbox::STATUS_SENT,
                NotificationOutbox::STATUS_FAILED,
                NotificationOutbox::STATUS_SKIPPED,
            ])
            ->max('updated_at');

        $lastSentAt = NotificationOutbox::query()->max('sent_at');

        $lastProcessedAt = $lastProcessed ? Carbon::parse($lastProcessed) : null;

        // pending, готовые к отправке прямо сейчас (ждут тика диспетчера).
        $pendingDue = NotificationOutbox::query()
            ->where('status', NotificationOutbox::STATUS_PENDING)
            ->where(function ($a) {
                $a->whereNull('available_at')->orWhere('available_at', '<=', Carbon::now());
            })
            ->count();

        return [
            'last_processed_at' => $lastProcessedAt?->toIso8601String(),
            'last_sent_at' => $lastSentAt ? Carbon::parse($lastSentAt)->toIso8601String() : null,
            'pending_due' => $pendingDue,
        ];
    }

    /**
     * Справочная инфа по стандартному `failed_jobs`. Очередь sync → таблица обычно
     * пуста (или отсутствует). Никогда не падаем, если таблицы нет.
     *
     * @return array<string,mixed>
     */
    private function failedJobsInfo(): array
    {
        $exists = Schema::hasTable('failed_jobs');

        return [
            'table_exists' => $exists,
            'count' => $exists ? (int) DB::table('failed_jobs')->count() : 0,
            'note' => 'queue sync — usually empty',
        ];
    }
}
