<?php

namespace Modules\Calculator\Tests\Feature\Bench;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Support\Bench\SyntheticTreeGenerator;
use RuntimeException;
use Tests\TestCase;

/**
 * t3: генератор синтетического дерева — инварианты структуры + гарды A-t3
 * (обязательные negative-кейсы: генератор массово пишет в БД).
 */
class SyntheticTreeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generate(int $count, int $seed = 42): array
    {
        return (new SyntheticTreeGenerator())->generate($count, $seed);
    }

    // ------------------------------------------------------------ инварианты

    public function testGeneratesExactCountWithSingleRootAndUniquePositions(): void
    {
        $stats = $this->generate(57);

        $this->assertSame(57, $stats['nodes']);
        $this->assertSame(57, (int) DB::table('members')->count());

        // Единственный корень.
        $roots = DB::table('members')->whereNull('parent_id')->pluck('id');
        $this->assertSame([1], $roots->map(fn ($id) => (int) $id)->all());

        // unique (parent_id, position) не нарушен.
        $dupes = DB::table('members')
            ->whereNotNull('parent_id')
            ->select('parent_id', 'position')
            ->groupBy('parent_id', 'position')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->assertSame(0, $dupes);

        // Не-корневые позиции только left|right.
        $badPos = DB::table('members')->whereNotNull('parent_id')
            ->whereNotIn('position', ['left', 'right'])->count();
        $this->assertSame(0, $badPos);
    }

    public function testPathIsParentPathPlusIdAndParentSponsorIdsAreSmaller(): void
    {
        $this->generate(40);

        $rows = DB::table('members')
            ->selectRaw('id, parent_id, sponsor_id, path::text AS path')
            ->orderBy('id')->get();
        $pathById = [];
        foreach ($rows as $row) {
            $pathById[(int) $row->id] = (string) $row->path;
        }

        foreach ($rows as $row) {
            $id = (int) $row->id;
            if ($row->parent_id === null) {
                $this->assertSame((string) $id, $row->path);
                $this->assertNull($row->sponsor_id);
                continue;
            }
            $parent = (int) $row->parent_id;
            // path = path(родителя) . '.' . id (валидный ltree, как у PlacementService).
            $this->assertSame($pathById[$parent] . '.' . $id, (string) $row->path);
            // id родителя < id ребёнка; sponsor — предок с меньшим id.
            $this->assertLessThan($id, $parent);
            $this->assertNotNull($row->sponsor_id);
            $this->assertLessThan($id, (int) $row->sponsor_id);
            // Спонсор — именно ПРЕДОК по placement-цепочке (heap: последовательные деления на 2).
            $ancestors = [];
            for ($a = intdiv($id, 2); $a >= 1; $a = intdiv($a, 2)) {
                $ancestors[] = $a;
            }
            $this->assertContains((int) $row->sponsor_id, $ancestors);
        }
    }

    public function testSameSeedIsDeterministicAcrossRuns(): void
    {
        $first = $this->generate(60, seed: 777);

        // Повторный прогон в чистую таблицу (никто ещё не ссылается на members).
        DB::table('members')->delete();
        $second = $this->generate(60, seed: 777);

        $this->assertSame($first['checksum'], $second['checksum']);

        // Другой seed — другая структура (страховка от вырожденного checksum).
        DB::table('members')->delete();
        $third = $this->generate(60, seed: 778);
        $this->assertNotSame($first['checksum'], $third['checksum']);
    }

    // ------------------------------------------------------------ гарды A-t3

    public function testRefusesInProductionEnvironmentWithoutWriting(): void
    {
        $originalEnv = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('production');
            $this->generate(10);
        } finally {
            $this->app['env'] = $originalEnv;
            $this->assertSame(0, (int) DB::table('members')->count(), 'гард обязан отработать до записи');
        }
    }

    public function testRefusesWhenMembersNotEmptyWithoutWriting(): void
    {
        $this->generate(5);

        try {
            $this->generate(5);
            $this->fail('ожидался отказ: members непуста');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('members непуста', $e->getMessage());
        }

        $this->assertSame(5, (int) DB::table('members')->count(), 'ни одной новой строки');
    }

    public function testDatabaseAllowlistAcceptsBenchAndTestNamesOnly(): void
    {
        // Позитив: izigo_bench + любой izigo_test* (в т.ч. текущая тест-БД).
        SyntheticTreeGenerator::assertDatabaseAllowed('izigo_bench');
        SyntheticTreeGenerator::assertDatabaseAllowed('izigo_test');
        SyntheticTreeGenerator::assertDatabaseAllowed('izigo_test_t3');
        SyntheticTreeGenerator::assertDatabaseAllowed((string) DB::connection()->getDatabaseName());

        // Негатив: прод/чужие имена БД — отказ.
        foreach (['izigo', 'postgres', 'izigo_prod', 'bench_izigo'] as $bad) {
            try {
                SyntheticTreeGenerator::assertDatabaseAllowed($bad);
                $this->fail("ожидался отказ для БД {$bad}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('allowlist', $e->getMessage());
            }
        }
    }
}
