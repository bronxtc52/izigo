<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Models\V2\CutoverLog;
use Modules\Calculator\Models\V2\ParityDiff;
use Modules\Calculator\V2\Services\Cutover\ParityCheckService;

/**
 * mh-full-plan T15 (W6): паритетный прогон V1-движок vs V2-модель (READ-ONLY оракул
 * для owner-гейта). Ничего не пишет в ledger/wallets/lots; сохраняет отчёт в
 * v2_parity_runs / v2_parity_diffs и печатает таблицу per-member (match / mismatch /
 * v2_only / plan_change) + сводку. Расхождения по новым механикам V2 — не ошибка.
 */
class ParityCheckCommand extends Command
{
    protected $signature = 'calc-v2:parity-check
        {--members= : CSV id партнёров для охвата (по умолчанию — вся сеть)}';

    protected $description = 'V2 паритет: read-only сравнение V1-движка и V2-модели (оракул для owner-гейта)';

    public function handle(ParityCheckService $parity): int
    {
        $memberIds = null;
        if ($this->option('members')) {
            $memberIds = array_values(array_filter(array_map(
                fn ($s) => (int) trim($s),
                explode(',', (string) $this->option('members')),
            )));
        }

        $this->info('=== PARITY CHECK — read-only (V1-движок vs V2-модель) ===');
        $run = $parity->run($memberIds);

        $rows = [];
        foreach ($run->diffs()->orderBy('member_id')->orderBy('check')->get() as $d) {
            $rows[] = [
                $d->member_id,
                $d->check,
                $d->v1_amount_cents,
                $d->v2_amount_cents,
                $d->delta_cents,
                $this->tag($d->classification),
            ];
        }
        $this->table(['member', 'check', 'v1_cents', 'v2_cents', 'delta', 'class'], $rows);

        $summary = $run->summary ?? [];
        $byClass = $summary['by_classification'] ?? [];
        $this->newLine();
        $this->line(sprintf('Прогон #%d: партнёров=%d', $run->id, $summary['members'] ?? 0));
        $this->line(sprintf(
            'match=%d · plan_change=%d · v2_only=%d · mismatch=%d',
            $byClass[ParityDiff::CLASS_MATCH] ?? 0,
            $byClass[ParityDiff::CLASS_PLAN_CHANGE] ?? 0,
            $byClass[ParityDiff::CLASS_V2_ONLY] ?? 0,
            $byClass[ParityDiff::CLASS_MISMATCH] ?? 0,
        ));
        $this->line(sprintf(
            'V1 денежная база (available) = %d центов · V2 проекция ОС opening = %d центов · сохранение: %s',
            $run->v1_total_cents, $run->v2_total_cents,
            ($summary['conservation_ok'] ?? false) ? 'ДА' : 'НЕТ',
        ));
        $this->line(sprintf('Необъяснённая дельта = %d центов', $run->unexplained_delta_cents));
        $this->line(sprintf(
            'Деньги «в полёте» (НЕ переносятся, остаются на V1): held = %d центов у %d партнёров · clawback-долг = %d центов',
            $summary['held_total_cents'] ?? 0,
            $summary['members_with_held'] ?? 0,
            $summary['clawback_total_cents'] ?? 0,
        ));
        if (($summary['members_with_held'] ?? 0) > 0) {
            $this->warn('Есть открытые выводы (held>0): разрулите их ДО cutover — cutover-migrate --commit будет заблокирован.');
        }

        $drift = $summary['engine_vs_persisted_drift'] ?? [];
        if ($drift !== []) {
            $this->warn(sprintf('Внимание: движок V1 разошёлся с persisted earnings у %d партнёров (дрейф read-модели).', count($drift)));
        }

        CutoverLog::query()->create([
            'action' => CutoverLog::ACTION_PARITY,
            'phase' => CutoverLog::PHASE_PRE,
            'actor' => 'cli',
            'dry_run' => true,
            'amount_cents' => $run->unexplained_delta_cents,
            'detail' => ['parity_run_id' => $run->id, 'acceptable' => $run->isAcceptable()],
        ]);

        if ($run->isAcceptable()) {
            $this->info('Отчёт ПРИЕМЛЕМ (unexplained delta = 0). Owner-accept — отдельным решением перед флипом.');

            return self::SUCCESS;
        }

        $this->error('Отчёт НЕ приемлем: есть mismatch (unexplained delta > 0). Разберите расхождения ДО cutover.');

        return self::FAILURE;
    }

    private function tag(string $classification): string
    {
        return match ($classification) {
            ParityDiff::CLASS_MATCH => 'match',
            ParityDiff::CLASS_MISMATCH => 'MISMATCH',
            ParityDiff::CLASS_V2_ONLY => 'v2_only',
            ParityDiff::CLASS_PLAN_CHANGE => 'plan_change',
            default => $classification,
        };
    }
}
