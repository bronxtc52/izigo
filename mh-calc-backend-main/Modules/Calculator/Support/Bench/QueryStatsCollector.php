<?php

namespace Modules\Calculator\Support\Bench;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * t3 (перф-бенчмарк): лёгкий агрегатор DB::listen для замеров.
 *
 * Считает число запросов и суммарное SQL-время окна замера + копит топ
 * нормализованных statement'ов, НЕ храня полный лог запросов (enableQueryLog
 * на 20k-дереве раздул бы память и исказил peak-замер). Слушатель
 * регистрируется один раз (register()); окна включаются start()/stop() —
 * снять слушателя в Laravel нельзя, поэтому вне окна он no-op по флагу.
 */
final class QueryStatsCollector
{
    private bool $registered = false;

    private bool $active = false;

    private int $windowCount = 0;

    private float $windowTimeMs = 0.0;

    /** @var array<string, array{count: int, time_ms: float}> агрегат с последнего resetAggregate() */
    private array $aggregate = [];

    /** Зарегистрировать слушателя (идемпотентно; DB::listen необратим — вне окон no-op). */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        DB::listen(function (QueryExecuted $query): void {
            $this->onQuery($query);
        });
        $this->registered = true;
    }

    /** Открыть окно замера (обнуляет счётчики окна). */
    public function start(): void
    {
        $this->windowCount = 0;
        $this->windowTimeMs = 0.0;
        $this->active = true;
    }

    /**
     * Закрыть окно и вернуть его метрики.
     *
     * @return array{sql_count: int, sql_time_ms: float}
     */
    public function stop(): array
    {
        $this->active = false;

        return [
            'sql_count' => $this->windowCount,
            'sql_time_ms' => round($this->windowTimeMs, 2),
        ];
    }

    /** Сбросить накопленный агрегат нормализованных statement'ов (напр. между размерами). */
    public function resetAggregate(): void
    {
        $this->aggregate = [];
    }

    /**
     * Топ-N нормализованных SQL по суммарному времени (с последнего resetAggregate()).
     *
     * @return list<array{sql: string, count: int, time_ms: float}>
     */
    public function top(int $n = 10): array
    {
        $rows = [];
        foreach ($this->aggregate as $sql => $agg) {
            $rows[] = ['sql' => $sql, 'count' => $agg['count'], 'time_ms' => round($agg['time_ms'], 2)];
        }
        usort($rows, fn (array $a, array $b) => $b['time_ms'] <=> $a['time_ms']);

        return array_slice($rows, 0, $n);
    }

    /**
     * Нормализация SQL для группировки: схлопнуть пробелы, плейсхолдер-списки
     * IN (?, ?, …) и числовые литералы — чтобы чанки/страницы попадали в одну строку.
     */
    public static function normalize(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;
        $sql = preg_replace('/\((\s*\?\s*,)+\s*\?\s*\)/', '(?…)', $sql) ?? $sql;
        // Повторяющиеся VALUES-кортежи bulk-insert'а → один маркер.
        $sql = preg_replace('/(\(\?…\)\s*,\s*)+\(\?…\)/', '(?…)×N', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', '{n}', $sql) ?? $sql;

        if (mb_strlen($sql) > 200) {
            $sql = mb_substr($sql, 0, 197) . '…';
        }

        return $sql;
    }

    private function onQuery(QueryExecuted $query): void
    {
        if (! $this->active) {
            return;
        }

        $this->windowCount++;
        $this->windowTimeMs += (float) $query->time;

        $key = self::normalize($query->sql);
        if (! isset($this->aggregate[$key])) {
            $this->aggregate[$key] = ['count' => 0, 'time_ms' => 0.0];
        }
        $this->aggregate[$key]['count']++;
        $this->aggregate[$key]['time_ms'] += (float) $query->time;
    }
}
