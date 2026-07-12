<?php

namespace Modules\Calculator\V2\Services\Cutover;

use Modules\Calculator\Domain\CompensationEngine;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\V2\ParityDiff;
use Modules\Calculator\Models\V2\ParityRun;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Services\LedgerService;

/**
 * mh-full-plan T15 (W6): паритетный оракул V1 vs V2 для owner-гейта. READ-ONLY —
 * ни одной записи в ledger/wallets/lots; результат — строки v2_parity_runs / v2_parity_diffs.
 *
 * ВАЖНО: V2 — ДРУГАЯ модель (4 ранга → 12 статусов, новые бонусы, старт PV/тиров/
 * статусов с нуля по решению владельца). Сопоставляется ТОЛЬКО то, что обязано
 * совпасть по семантике; новые механики V2 помечаются v2_only, а поглощение V1-дохода
 * opening-балансом — plan_change (by-design, не блокирует).
 *
 * Проверки на партнёра:
 *  - money_conservation (ОБЯЗАНА совпасть): V1 available (кэш) vs свёртка ledger по
 *    member_available. Расхождение = дрейф кэша = mismatch (блокирует accept): именно
 *    эта сумма уйдёт на ОС бессрочным opening-лотом, она обязана быть достоверной.
 *  - tree_composition (ОБЯЗАНА совпасть): узел присутствует в сети V1-движка и его
 *    спонсор == members.sponsor_id (V2 читает ту же генеалогию, дерево не форкается).
 *  - accrued_income (plan_change): доход V1-движка (read-only прогон) vs начисленное V2
 *    на исторических данных (= 0, старт с нуля). Не потеря — сохраняется как opening ОС.
 *
 * Критерий приёмки отчёта: unexplained_delta_cents == 0 (Σ|delta| строк mismatch).
 */
class ParityCheckService
{
    public function __construct(
        private readonly EloquentNetworkRepository $networkRepo,
        private readonly EloquentPlanRepository $planRepo,
    ) {
    }

    /**
     * @param int[]|null $memberIds охват (null = вся сеть)
     */
    public function run(?array $memberIds = null, ?int $createdBy = null): ParityRun
    {
        $startedAt = now();

        // --- V1: read-only прогон движка (репродуцирует начисления БЕЗ записи) ---
        $plan = $this->planRepo->load();
        $network = $this->networkRepo->load();
        $result = (new CompensationEngine($plan))->calculate($network);

        $v1EngineByMember = [];
        foreach ($result->lines() as $line) {
            $v1EngineByMember[$line->recipientId] = ($v1EngineByMember[$line->recipientId] ?? 0) + $line->amount->cents;
        }

        // V1 persisted earnings (для примечания о дрейфе движок vs снапшот).
        $v1PersistedByMember = MemberEarning::query()->pluck('total', 'member_id')
            ->map(fn ($t) => $this->decimalToCents((string) $t))->all();

        // Денежная база: available (кэш) и свёртка ledger member_available.
        $availByMember = MemberWallet::query()->pluck('available_cents', 'member_id')
            ->map(fn ($v) => (int) $v)->all();
        $ledgerAvailByMember = $this->ledgerFoldForAccount(LedgerService::ACC_AVAILABLE);

        // Генеалогия: спонсоры из members.
        $sponsorByMember = Member::query()->pluck('sponsor_id', 'id')
            ->map(fn ($v) => $v !== null ? (int) $v : null)->all();
        $networkSponsor = [];
        foreach ($network->orderedById() as $node) {
            $networkSponsor[$node->id] = $node->sponsorId ?: null;
        }

        $ids = $memberIds ?? array_values(array_unique([
            ...array_keys($v1EngineByMember),
            ...array_keys($v1PersistedByMember),
            ...array_keys($availByMember),
            ...array_keys($ledgerAvailByMember),
            ...array_keys($sponsorByMember),
        ]));
        sort($ids);

        $run = ParityRun::query()->create([
            'status' => ParityRun::STATUS_RUNNING,
            'scope' => $memberIds !== null ? ['members' => array_values($memberIds)] : null,
            'created_by' => $createdBy,
            'started_at' => $startedAt,
        ]);

        $v1Total = 0;
        $v2Total = 0;
        $unexplained = 0;
        $byClass = [
            ParityDiff::CLASS_MATCH => 0,
            ParityDiff::CLASS_MISMATCH => 0,
            ParityDiff::CLASS_V2_ONLY => 0,
            ParityDiff::CLASS_PLAN_CHANGE => 0,
        ];
        $engineDrift = [];
        $diffRows = [];

        foreach ($ids as $mid) {
            $mid = (int) $mid;

            // --- money_conservation ---
            $avail = $availByMember[$mid] ?? 0;
            $ledgerAvail = $ledgerAvailByMember[$mid] ?? 0;
            $moneyDelta = $avail - $ledgerAvail;
            $moneyClass = $moneyDelta === 0 ? ParityDiff::CLASS_MATCH : ParityDiff::CLASS_MISMATCH;
            $diffRows[] = $this->row($run->id, $mid, ParityDiff::CHECK_MONEY, $avail, $ledgerAvail, $moneyDelta, $moneyClass,
                $moneyClass === ParityDiff::CLASS_MATCH
                    ? 'V1 available → ОС opening (бессрочный лот); сумма достоверна, деньги сохраняются'
                    : 'ДРЕЙФ кэша member_wallets vs ledger — перенос был бы неверным, cutover блокируется');
            $v1Total += $avail;
            $v2Total += $ledgerAvail; // = проекция ОС opening
            if ($moneyClass === ParityDiff::CLASS_MISMATCH) {
                $unexplained += abs($moneyDelta);
            }
            $byClass[$moneyClass]++;

            // --- tree_composition ---
            $dbSponsor = $sponsorByMember[$mid] ?? null;
            $netSponsor = $networkSponsor[$mid] ?? null;
            $inNetwork = array_key_exists($mid, $networkSponsor);
            $treeOk = $inNetwork && $dbSponsor === $netSponsor;
            $treeClass = $treeOk ? ParityDiff::CLASS_MATCH : ParityDiff::CLASS_MISMATCH;
            $diffRows[] = $this->row($run->id, $mid, ParityDiff::CHECK_TREE, 0, 0, 0, $treeClass,
                $treeOk
                    ? 'Генеалогия совпадает (V2 читает те же members.sponsor_id/path)'
                    : 'Узел отсутствует в сети V1-движка или спонсор разошёлся (db=' . var_export($dbSponsor, true) . ', net=' . var_export($netSponsor, true) . ')');
            if (! $treeOk) {
                $unexplained += 0; // структурное расхождение не денежное, но фиксируем как mismatch
            }
            $byClass[$treeClass]++;

            // --- accrued_income (plan_change / match) ---
            $v1Accrued = $v1EngineByMember[$mid] ?? 0;
            $v2Accrued = 0; // старт с нуля, без бэкфила (решение владельца)
            $accClass = $v1Accrued === 0 ? ParityDiff::CLASS_MATCH : ParityDiff::CLASS_PLAN_CHANGE;
            $diffRows[] = $this->row($run->id, $mid, ParityDiff::CHECK_ACCRUED, $v1Accrued, $v2Accrued, $v1Accrued - $v2Accrued, $accClass,
                $v1Accrued === 0
                    ? 'Нет дохода V1'
                    : 'Доход V1 сохраняется как opening ОС; V2 стартует с нуля (нет бэкфила PV/тиров/статусов)');
            $byClass[$accClass]++;

            // Служебное примечание: движок V1 разошёлся с persisted earnings (дрейф read-модели).
            $persisted = $v1PersistedByMember[$mid] ?? 0;
            if ($v1Accrued !== $persisted) {
                $engineDrift[] = ['member_id' => $mid, 'engine_cents' => $v1Accrued, 'persisted_cents' => $persisted];
            }
        }

        if ($diffRows !== []) {
            ParityDiff::query()->insert($diffRows);
        }

        $run->update([
            'status' => ParityRun::STATUS_DONE,
            'v1_total_cents' => $v1Total,
            'v2_total_cents' => $v2Total,
            'unexplained_delta_cents' => $unexplained,
            'summary' => [
                'members' => count($ids),
                'by_classification' => $byClass,
                'conservation_ok' => $v1Total === $v2Total && $unexplained === 0,
                'engine_vs_persisted_drift' => $engineDrift,
                'note' => 'V2 — другая модель; сопоставлены только обязанные совпасть выходы (деньги, дерево). '
                    . 'Новые механики V2 (PV/тиры/статусы/бонусы) стартуют с нуля и здесь помечены plan_change/v2_only.',
            ],
            'finished_at' => now(),
        ]);

        return $run->fresh();
    }

    /** @return array{run_id:int,member_id:int,check:string,v1_amount_cents:int,v2_amount_cents:int,delta_cents:int,classification:string,note:string} */
    private function row(int $runId, int $memberId, string $check, int $v1, int $v2, int $delta, string $class, string $note): array
    {
        return [
            'run_id' => $runId,
            'member_id' => $memberId,
            'check' => $check,
            'v1_amount_cents' => $v1,
            'v2_amount_cents' => $v2,
            'delta_cents' => $delta,
            'classification' => $class,
            'note' => $note,
        ];
    }

    /** @return array<int,int> [memberId] = Σcredit − Σdebit по счёту */
    private function ledgerFoldForAccount(string $account): array
    {
        return LedgerEntry::query()
            ->where('account_type', $account)
            ->whereNotNull('member_id')
            ->selectRaw("member_id, SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END) AS bal", [LedgerService::CR])
            ->groupBy('member_id')
            ->pluck('bal', 'member_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function decimalToCents(string $value): int
    {
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '0');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return (int) $int * 100 + (int) $frac;
    }
}
