<?php

namespace Modules\Calculator\Support\Bench;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * t3 (перф-бенчмарк): детерминированный генератор синтетического бинарного дерева
 * участников для бенчмарка движка пересчёта. ТОЛЬКО обвязка — ядро движка не трогает.
 *
 * Форма — почти полное бинарное дерево по heap-нумерации: узел i имеет parent = i/2,
 * position = left|right по чётности. Инварианты members соблюдены:
 *  - единственный корень (parent_id IS NULL ровно у id=1);
 *  - unique (parent_id, position) — у каждого родителя ≤ 1 ноги каждой стороны;
 *  - path = path(родителя) . '.' . id (валидный ltree, как пишет PlacementService);
 *  - id родителя < id ребёнка и sponsor_id < id (спонсор — случайный предок по
 *    placement-цепочке); на этом порядке стоит EloquentNetworkRepository::load().
 *
 * Пакеты распределяются fixed seed (mt_srand) ~50/30/20 по первым трём пакетам.
 * Вставка — чанковыми bulk-insert (~1000 строк): 20k×(PlacementService: транзакция+
 * локи+BFS) неприемлемо долго; PlacementService используется как спецификация
 * инвариантов, но не вызывается.
 *
 * ГАРДЫ (амендмент A-t3) — проверяются ДО любой записи:
 *  - environment('production') → отказ всегда;
 *  - имя БД обязано быть из allowlist (izigo_bench | izigo_test*) — генератор
 *    массово пишет в members и не должен уметь работать по чужой базе;
 *  - непустая members → отказ (очистку делает ТОЛЬКО явный --fresh команды).
 *
 * НЕ подключать к DatabaseSeeder / прод-сидерам: это исключительно bench-фикстура.
 */
final class SyntheticTreeGenerator
{
    /** Точные имена БД, где разрешена работа бенча. */
    public const DB_ALLOWLIST_EXACT = ['izigo_bench'];

    /** Разрешённый префикс имён тестовых БД (izigo_test, izigo_test_t3, …). */
    public const DB_ALLOWLIST_PREFIX = 'izigo_test';

    /** Отказ в production-окружении (до любой записи). */
    public static function assertNotProduction(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('bench: запуск в production запрещён (guard A-t3).');
        }
    }

    /** Отказ, если имя БД вне allowlist (izigo_bench | izigo_test*). */
    public static function assertDatabaseAllowed(string $dbName): void
    {
        $allowed = in_array($dbName, self::DB_ALLOWLIST_EXACT, true)
            || str_starts_with($dbName, self::DB_ALLOWLIST_PREFIX);

        if (! $allowed) {
            throw new RuntimeException(sprintf(
                'bench: БД "%s" вне allowlist (%s | %s*) — работа запрещена (guard A-t3).',
                $dbName,
                implode('|', self::DB_ALLOWLIST_EXACT),
                self::DB_ALLOWLIST_PREFIX,
            ));
        }
    }

    /** Отказ при непустой members: чистит только явный --fresh команды (guard A-t3). */
    public static function assertMembersEmpty(): void
    {
        if (DB::table('members')->exists()) {
            throw new RuntimeException(
                'bench: таблица members непуста — генерация запрещена. '
                . 'Очистка только явным флагом --fresh команды calculator:bench-engine.'
            );
        }
    }

    /**
     * Сгенерировать дерево из $count участников (детерминированно по $seed).
     *
     * @return array{nodes: int, checksum: string, root_id: int, leaf_id: int, package_ids: list<int>}
     */
    public function generate(int $count, int $seed = 20260720, int $chunkSize = 1000): array
    {
        if ($count < 1) {
            throw new RuntimeException('bench: размер дерева должен быть ≥ 1.');
        }

        // Все гарды — ДО любой записи.
        self::assertNotProduction();
        self::assertDatabaseAllowed((string) DB::connection()->getDatabaseName());
        self::assertMembersEmpty();

        $packageIds = DB::table('calculator_packages')->orderBy('sort')->pluck('id')
            ->map(fn ($id) => (int) $id)->values()->all();
        if ($packageIds === []) {
            throw new RuntimeException('bench: нет calculator_packages — сначала прогоните миграции.');
        }

        mt_srand($seed);
        $now = now();
        $hash = hash_init('sha1');
        $paths = [];
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $parent = $i > 1 ? intdiv($i, 2) : null;
            $position = $i === 1 ? null : ($i % 2 === 0 ? 'left' : 'right');

            // Спонсор — случайный предок по placement-цепочке (все предки имеют меньший id).
            $sponsor = null;
            if ($i > 1) {
                $ancestors = [];
                for ($a = intdiv($i, 2); $a >= 1; $a = intdiv($a, 2)) {
                    $ancestors[] = $a;
                }
                $sponsor = $ancestors[mt_rand(0, count($ancestors) - 1)];
            }

            // ~50/30/20 по первым трём пакетам (fixed seed → детерминированно).
            $r = mt_rand(1, 100);
            $package = $r <= 50
                ? $packageIds[0]
                : ($r <= 80 ? ($packageIds[1] ?? $packageIds[0]) : ($packageIds[2] ?? $packageIds[count($packageIds) - 1]));

            $path = $parent === null ? (string) $i : $paths[$parent] . '.' . $i;
            $paths[$i] = $path;

            $rows[] = [
                'id' => $i,
                'telegram_id' => 900_000_000 + $i,
                'telegram_username' => 'bench' . $i,
                'sponsor_id' => $sponsor,
                'parent_id' => $parent,
                'position' => $position,
                'package_id' => $package,
                'name' => 'Bench #' . $i,
                'ref_code' => sprintf('B%015d', $i),
                'status' => 'active',
                'path' => $path,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            hash_update($hash, "{$i}|{$parent}|{$position}|{$sponsor}|{$package};");

            if (count($rows) === $chunkSize) {
                DB::table('members')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('members')->insert($rows);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Явные id обошли sequence — выровнять, чтобы дальнейшие insert'ы не падали.
            DB::statement("SELECT setval(pg_get_serial_sequence('members', 'id'), ?)", [$count]);
        }

        return [
            'nodes' => $count,
            'checksum' => hash_final($hash),
            'root_id' => 1,
            'leaf_id' => $count,
            'package_ids' => $packageIds,
        ];
    }
}
