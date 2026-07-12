<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\V2\ParityDiff;
use Modules\Calculator\Models\V2\ParityRun;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsCutoverData;
use Modules\Calculator\V2\Services\Cutover\ParityCheckService;
use Tests\TestCase;

/**
 * T15 [ДЕНЬГИ, обязательный]: паритетный оракул calc-v2:parity-check (read-only).
 * Согласованная таблица на 5 участниках: money_conservation match, сохранение суммы,
 * unexplained = 0; дрейф кэша → mismatch, отчёт не приемлем.
 */
class ParityCheckTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCutoverData;

    public function test_parity_table_coherent_on_five_members(): void
    {
        $ids = [];
        $root = null;
        foreach ([9001 => 10000, 9002 => 5000, 9003 => 250, 9004 => 0, 9005 => 999] as $tg => $cents) {
            $m = $this->seedMember($tg, $root);
            $root ??= $m;
            $ids[] = $m->id;
            if ($cents > 0) {
                $this->deposit($m->id, $cents);
            }
        }

        $this->artisan('calc-v2:parity-check')->assertExitCode(0);

        $run = ParityRun::query()->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(ParityRun::STATUS_DONE, $run->status);
        // Денежная база и проекция ОС opening совпадают (сумма сохраняется).
        $this->assertSame(16249, (int) $run->v1_total_cents);
        $this->assertSame(16249, (int) $run->v2_total_cents);
        $this->assertSame(0, (int) $run->unexplained_delta_cents);
        $this->assertTrue($run->isAcceptable());
        $this->assertTrue($run->summary['conservation_ok']);

        // По каждому участнику money_conservation классифицирован match, дельта 0.
        foreach ($ids as $mid) {
            $row = ParityDiff::query()->where('run_id', $run->id)
                ->where('member_id', $mid)->where('check', ParityDiff::CHECK_MONEY)->first();
            $this->assertNotNull($row);
            $this->assertSame(ParityDiff::CLASS_MATCH, $row->classification);
            $this->assertSame(0, (int) $row->delta_cents);
        }
        // Три проверки на участника (money / tree / accrued).
        $this->assertSame(count($ids) * 3, ParityDiff::query()->where('run_id', $run->id)->count());
        // Ни одного mismatch.
        $this->assertSame(0, ParityDiff::query()->where('run_id', $run->id)
            ->where('classification', ParityDiff::CLASS_MISMATCH)->count());
    }

    public function test_parity_flags_cache_drift_as_mismatch(): void
    {
        $m = $this->seedMember(9001);
        $this->deposit($m->id, 10000);
        // Кэш разошёлся с ledger (available завышен на 500). Один корневой узел.
        MemberWallet::query()->where('member_id', $m->id)->update(['available_cents' => 10500]);

        $run = app(ParityCheckService::class)->run();

        $money = ParityDiff::query()->where('run_id', $run->id)
            ->where('member_id', $m->id)->where('check', ParityDiff::CHECK_MONEY)->first();
        $this->assertSame(ParityDiff::CLASS_MISMATCH, $money->classification);
        $this->assertSame(500, (int) $money->delta_cents);
        $this->assertSame(500, (int) $run->unexplained_delta_cents);
        $this->assertFalse($run->isAcceptable());
    }

    /**
     * MF-1: рассинхрон генеалогии (узел вне сети V1-движка) при НУЛЕВОЙ денежной дельте
     * обязан отклонять отчёт. Раньше isAcceptable() гейтил только unexplained==0 →
     * tree-mismatch печатался, но отчёт считался приемлемым (дыра go/no-go).
     */
    public function test_tree_mismatch_blocks_accept_even_with_zero_money_delta(): void
    {
        $m = $this->seedMember(9001);
        $this->deposit($m->id, 10000); // деньги полностью консистентны → нет денежного mismatch

        // Охват включает несуществующий узел (member удалён/опечатка в scope):
        // денежной дельты нет, но дерево расходится (узла нет в сети V1-движка).
        $run = app(ParityCheckService::class)->run([$m->id, 999999]);

        $this->assertSame(0, (int) $run->unexplained_delta_cents);
        $tree = ParityDiff::query()->where('run_id', $run->id)
            ->where('member_id', 999999)->where('check', ParityDiff::CHECK_TREE)->first();
        $this->assertNotNull($tree);
        $this->assertSame(ParityDiff::CLASS_MISMATCH, $tree->classification);
        // Гейт ОБЯЗАН отклонить: нулевая денежная дельта не делает отчёт приемлемым при mismatch дерева.
        $this->assertFalse($run->isAcceptable());
    }

    public function test_parity_command_exits_nonzero_on_tree_mismatch(): void
    {
        $m = $this->seedMember(9001);
        $this->deposit($m->id, 10000);

        $this->artisan('calc-v2:parity-check', ['--members' => $m->id . ',999999'])
            ->assertExitCode(1);
    }

    /**
     * MF-2(а/б): held-баланс (заявка на вывод «в полёте») обязан быть виден на гейте —
     * отдельной строкой отчёта и в сводных тоталах. Раньше оракул читал только available.
     */
    public function test_parity_surfaces_held_balance(): void
    {
        $m = $this->seedMember(9001);
        $this->deposit($m->id, 10000);
        $this->hold($m->id, 3000); // 3000 в held, 7000 в available

        $run = app(ParityCheckService::class)->run();

        $held = ParityDiff::query()->where('run_id', $run->id)
            ->where('member_id', $m->id)->where('check', ParityDiff::CHECK_HELD)->first();
        $this->assertNotNull($held, 'held обязан быть виден в отчёте паритета');
        $this->assertSame(3000, (int) $held->v1_amount_cents);
        $this->assertSame(ParityDiff::CLASS_MATCH, $held->classification); // кэш == ledger, дрейфа нет
        $this->assertSame(3000, (int) ($run->summary['held_total_cents'] ?? 0));
        $this->assertSame(1, (int) ($run->summary['members_with_held'] ?? 0));
    }
}
