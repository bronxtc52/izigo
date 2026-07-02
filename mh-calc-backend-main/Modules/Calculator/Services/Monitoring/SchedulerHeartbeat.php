<?php

namespace Modules\Calculator\Services\Monitoring;

use Illuminate\Support\Facades\Cache;

/**
 * B-5 (launch-readiness audit) — ПРЯМОЙ heartbeat планировщика Laravel.
 *
 * Контекст: фон проекта = `php artisan schedule:work` (docker/start.sh), НЕ async-очередь.
 * Раньше падение планировщика было тихим: контейнер оставался Healthy, а денежные кроны
 * (commerce:tonpay-poll, notifications:outbox-dispatch, commerce:payouts-poll,
 * commerce:autoship-run, leads:expire) молча вставали. Этот класс даёт прямой сигнал:
 * scheduled-команда `scheduler:heartbeat` ежеминутно оставляет свежую метку времени, а
 * health-эндпоинт проверяет её свежесть.
 *
 * Хранилище — Cache: в проде драйвер `file` (метка лежит на диске и ВИДНА из web-процесса,
 * который отдаёт /api/health, хотя писал её отдельный процесс schedule:work). Не завязано
 * на БД, чтобы отделять «упал планировщик» от «недоступна БД».
 */
class SchedulerHeartbeat
{
    /** Ключ кэша с unix-таймстампом последнего тика планировщика. */
    public const CACHE_KEY = 'scheduler:heartbeat';

    /**
     * Порог свежести (сек). Heartbeat пишется everyMinute → нормальный возраст ≤ ~60с.
     * 180с = допуск на 2 пропущенных тика, дальше метка считается протухшей.
     */
    public const FRESH_SECONDS = 180;

    /** TTL записи в кэше — заметно больше порога, чтобы протухший heartbeat читался как «старый», а не «нет». */
    private const TTL_SECONDS = 3600;

    /** Оставить свежую метку времени (зовётся из scheduler:heartbeat и из start.sh при старте). */
    public function touch(): int
    {
        $now = time();
        Cache::put(self::CACHE_KEY, $now, self::TTL_SECONDS);

        return $now;
    }

    /** Unix-таймстамп последнего тика или null, если метки ещё нет. */
    public function lastBeatAt(): ?int
    {
        $ts = Cache::get(self::CACHE_KEY);

        return $ts === null ? null : (int) $ts;
    }

    /** Возраст последнего тика в секундах или null, если метки нет. */
    public function ageSeconds(): ?int
    {
        $ts = $this->lastBeatAt();

        return $ts === null ? null : max(0, time() - $ts);
    }

    /** Свежая ли метка (моложе порога). Нет метки → не свежая. */
    public function isFresh(): bool
    {
        $age = $this->ageSeconds();

        return $age !== null && $age <= self::FRESH_SECONDS;
    }
}
