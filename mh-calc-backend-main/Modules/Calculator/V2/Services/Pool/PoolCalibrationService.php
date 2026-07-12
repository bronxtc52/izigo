<?php

namespace Modules\Calculator\V2\Services\Pool;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\PoolCalibration;
use Modules\Calculator\V2\Models\PoolCalibrationItem;
use Modules\Calculator\V2\Models\ReferralReward;
use Modules\Calculator\V2\Models\StructureBonus;

/**
 * T11 — ЕДИНСТВЕННЫЙ владелец 60%-калибровки месяца (DEC-014/029/053, amendments MF-1/2).
 *
 * Числитель total_after_caps_cents = структурная-после-капов (обе половины месяца по
 * accrual_month) + месячное накопление глобального (capped_cents member-аллокаций).
 * НЕ входят: реферальная (MF-W3-3 — платится мгновенно на ОС, вне пула; в отчёте
 * отдельной строкой без factor), лидерский (DEC-029 — считается ПОСЛЕ от post-calibration
 * базы, иначе цикл), награды (owner-исключение Гейта A).
 *
 * factor_bps по PoolFactor (целочисленно). Применение:
 *  - структурная: НЕ постим здесь — перевод НС→ОС по закоммиченному factor_bps делает
 *    T02 NsToOsTransfer (per-member floor + дельта в company_pool_retained двойной записью);
 *    items(structure) — проекция этого перевода (state=projected).
 *  - глобальная: пишем final_cents member-аллокаций (distribute largest-remainder) ДО
 *    финализации месяца T09 (order 500 < 900); неаллоцированный остаток факторингу не
 *    подлежит (уже деньги компании).
 *
 * Идемпотентность/BR-POOL-002: каждый прогон СУПЕРСИДИТ прежнюю committed-строку месяца и
 * пишет новую с run_version+1 (прежние суммы не перезаписываются). Ровно одна committed на
 * месяц гарантируется partial unique index. Всё под ACTIVATION_LOCK (сериализация с
 * активациями/пересчётами V1 и закрытием периода).
 */
class PoolCalibrationService
{
    public function __construct(
        private readonly PolicyVersionResolver $policies,
        private readonly PeriodBvProvider $bvProvider,
        private readonly ActivationService $activation,
    ) {
    }

    /**
     * Рассчитать и закоммитить калибровку месяца. Возвращает committed-строку.
     *
     * @param string|null $createdBy кто инициировал (система/owner-id) — для аудита
     */
    public function calibrateMonth(CalcPeriod $month, ?string $createdBy = 'system'): PoolCalibration
    {
        if ($month->period_type !== CalcPeriod::TYPE_MONTH) {
            throw new \InvalidArgumentException("60%-калибровка считается по month-периоду, дан {$month->period_type}.");
        }

        return DB::transaction(function () use ($month, $createdBy) {
            // Единый порядок локов с V1 (деньги не задваиваются); при вызове из month-close
            // лок уже держит оркестратор — повторный xact-acquire того же ключа безопасен.
            $this->activation->acquireActivationLock();

            $policy = $this->policies->forDate($month->starts_at);
            $calibration = $policy->calibration();
            $rateBps = $calibration->rateBp;
            $include = $calibration->include;
            $monthCode = $month->code;

            // --- Числитель: структурная после капов, агрегат по участнику (обе половины) ---
            $includeStructure = (bool) ($include['structure_after_caps'] ?? true);
            $structureByMember = $includeStructure ? $this->structureAfterCapsByMember($monthCode) : [];
            $structureTotal = array_sum($structureByMember);

            // --- Числитель: месячное накопление глобального (member-аллокации) ---
            $includeGlobal = (bool) ($include['global_pool_monthly'] ?? true);
            $globalMonth = GlobalBonusMonth::query()->where('month_period_id', $month->id)->first();
            $globalByAlloc = ($includeGlobal && $globalMonth !== null)
                ? $this->globalMemberCappedByAllocation($globalMonth)
                : [];
            $globalTotal = array_sum($globalByAlloc);

            // Реферальная — ИНФОРМАЦИОННО (вне числителя, MF-W3-3).
            $referralGross = $this->referralGrossCents($month);

            $baseBv = $this->bvProvider->periodBvCents($month);
            $totalAfterCaps = $structureTotal + $globalTotal;

            $factor = PoolFactor::forPeriod($baseBv, $rateBps, $totalAfterCaps);

            // --- Применение фактора ---
            // Структурная — per-member floor (зеркалит T02 NsToOsTransfer).
            $scaledStructureByMember = [];
            $scaledStructureTotal = 0;
            foreach ($structureByMember as $memberId => $raw) {
                $paid = $factor->scale($raw);
                $scaledStructureByMember[$memberId] = $paid;
                $scaledStructureTotal += $paid;
            }
            // Глобальная — largest-remainder (T11 сам владеет final_cents).
            $scaledGlobalByAlloc = $factor->distribute($globalByAlloc);
            $scaledGlobalTotal = array_sum($scaledGlobalByAlloc);

            $scaledTotal = $scaledStructureTotal + $scaledGlobalTotal;
            $companyRetained = $totalAfterCaps - $scaledTotal;

            // --- Персист (supersede прежней committed, новая версия) ---
            PoolCalibration::query()
                ->where('period_id', $month->id)
                ->where('status', PoolCalibration::STATUS_COMMITTED)
                ->update(['status' => PoolCalibration::STATUS_SUPERSEDED]);

            $runVersion = (int) PoolCalibration::query()->where('period_id', $month->id)->max('run_version') + 1;

            $row = PoolCalibration::query()->create([
                'period_id' => $month->id,
                'month' => $monthCode,
                'run_version' => $runVersion,
                'policy_version_id' => $policy->versionId(),
                'pool_rate_bps' => $rateBps,
                'base_bv_cents' => $baseBv,
                'pool_cap_cents' => $factor->poolCapCents,
                'structure_after_caps_cents' => $structureTotal,
                'global_after_caps_cents' => $globalTotal,
                'referral_gross_cents' => $referralGross,
                'total_after_caps_cents' => $totalAfterCaps,
                'factor_bps' => $factor->factorBps,
                'scaled_total_cents' => $scaledTotal,
                'company_retained_cents' => $companyRetained,
                'status' => PoolCalibration::STATUS_COMMITTED,
                'created_by' => $createdBy,
                'committed_at' => now(),
            ]);

            // --- items(structure) — проекция перевода НС→ОС (постит T02) ---
            foreach ($structureByMember as $memberId => $raw) {
                $paid = $scaledStructureByMember[$memberId];
                PoolCalibrationItem::query()->create([
                    'calibration_id' => $row->id,
                    'bonus_kind' => PoolCalibrationItem::KIND_STRUCTURE,
                    'member_id' => $memberId,
                    'source_ref' => $memberId,
                    'amount_after_caps_cents' => $raw,
                    'calibrated_cents' => $paid,
                    'retained_cents' => $raw - $paid,
                    'state' => PoolCalibrationItem::STATE_PROJECTED,
                ]);
            }

            // --- items(global) + запись final_cents (только draft-месяц) ---
            $applyGlobal = $globalMonth !== null && ! $globalMonth->isFinal();
            foreach ($globalByAlloc as $allocId => $raw) {
                $paid = $scaledGlobalByAlloc[$allocId];
                $alloc = GlobalBonusAllocation::query()->find($allocId);
                if ($applyGlobal && $alloc !== null) {
                    $alloc->update(['final_cents' => $paid]);
                }
                PoolCalibrationItem::query()->create([
                    'calibration_id' => $row->id,
                    'bonus_kind' => PoolCalibrationItem::KIND_GLOBAL,
                    'member_id' => $alloc?->member_id,
                    'source_ref' => $allocId,
                    'amount_after_caps_cents' => $raw,
                    'calibrated_cents' => $paid,
                    'retained_cents' => $raw - $paid,
                    'state' => $applyGlobal
                        ? PoolCalibrationItem::STATE_APPLIED
                        : PoolCalibrationItem::STATE_PROJECTED,
                ]);
            }

            return $row->refresh();
        });
    }

    /**
     * Структурная премия после индивидуальных капов, агрегат по участнику за месяц.
     * status calculated|posted (reversed — исключаем, деньги отозваны T12).
     *
     * @return array<int,int> member_id => Σ after_cap_cents (>0), отсортировано по member_id ASC
     */
    private function structureAfterCapsByMember(string $monthCode): array
    {
        $rows = StructureBonus::query()
            ->where('accrual_month', $monthCode)
            ->whereIn('status', [StructureBonus::STATUS_CALCULATED, StructureBonus::STATUS_POSTED])
            ->selectRaw('member_id, SUM(after_cap_cents) AS total')
            ->groupBy('member_id')
            ->orderBy('member_id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $total = (int) $r->total;
            if ($total > 0) {
                $out[(int) $r->member_id] = $total;
            }
        }

        return $out;
    }

    /**
     * capped_cents member-аллокаций месяца по id аллокации (детерминизм по id ASC).
     * UNALLOCATED-строки исключены (уже деньги компании — факторингу не подлежат).
     *
     * @return array<int,int> allocation_id => capped_cents (>0)
     */
    private function globalMemberCappedByAllocation(GlobalBonusMonth $globalMonth): array
    {
        $rows = $globalMonth->allocations()
            ->where('kind', GlobalBonusAllocation::KIND_MEMBER)
            ->orderBy('id')
            ->get(['id', 'capped_cents']);

        $out = [];
        foreach ($rows as $r) {
            $capped = (int) $r->capped_cents;
            if ($capped > 0) {
                $out[(int) $r->id] = $capped;
            }
        }

        return $out;
    }

    /** Σ gross_cents выплаченной реферальной месяца (информационно, вне числителя). */
    private function referralGrossCents(CalcPeriod $month): int
    {
        return (int) ReferralReward::query()
            ->where('status', ReferralReward::STATUS_POSTED)
            ->where('paid_at', '>=', $month->starts_at)
            ->where('paid_at', '<', $month->ends_at)
            ->sum('gross_cents');
    }
}
