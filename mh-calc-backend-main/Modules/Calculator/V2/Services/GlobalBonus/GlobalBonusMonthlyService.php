<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\GlobalQualificationAwardHook;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Contracts\StatusReader;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPool;
use Modules\Calculator\V2\Models\GlobalBonusQualification;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;

/**
 * T09 — ядро глобального бонуса (месячный расчёт, DEC-031..DEC-038):
 *
 *  1. global BV месяца = Σ bv_usd_cents PAID-снапшотов заказов месяца (минус reversed —
 *     reversal-снапшоты вводит T12; здесь суммируются все снапшоты окна).
 *  2. Пулы Director..VP: pool_amount = intdiv(global_bv × rate_bps, 10000).
 *  3. Квалификации (rank as-of конец месяца >= Director): shares = min(floor(
 *     referral_tree_pv / one_share_base(ownRank)), max_shares). Строка есть и при shares=0.
 *  4. Наследование пулов (DEC): доли владельца добавляются в его пул и ВСЕ нижние.
 *  5. Аллокация per pool: знаменатель = Σ долей (DEC-033); largest-remainder в центах
 *     ДО капа (DEC-035); кап 25% пула на участника (DEC-034); остаток/пустой пул →
 *     строка UNALLOCATED (member_id NULL, деньги остаются компании).
 *  6. final_cents = capped_cents (T11 60%-калибровка перезаписывает ДО финализации).
 *
 * Вся математика целочисленная (деньги — integer USD-центы, PV — целочисленные
 * микро-PV). Детерминизм: любой обход — по member_id ASC. Идемпотентность:
 * финальный месяц не пересчитывается (no-op), draft — байт-в-байт детерминированный
 * пересчёт (delete+recreate дочерних строк).
 */
class GlobalBonusMonthlyService
{
    public function __construct(
        private readonly PolicyVersionResolver $policies,
        private readonly StatusReader $ranks,
        private readonly ReferralTreePvMonthlyService $tree,
        private readonly GlobalQualificationAwardHook $awardHook,
    ) {
    }

    /**
     * (Пере)рассчитать снапшот месяца глобального бонуса. Идемпотентно:
     *  - месяц final → no-op (пересчёт запрещён);
     *  - месяц отсутствует/draft → полный детерминированный пересчёт.
     * НЕ финализирует (см. finalizeMonth — вызывается ПОСЛЕ шага калибровки T11).
     */
    public function allocateForMonth(CalcPeriod $monthPeriod): GlobalBonusMonth
    {
        if ($monthPeriod->period_type !== CalcPeriod::TYPE_MONTH) {
            throw new \InvalidArgumentException("Глобальный бонус считается по month-периоду, дан {$monthPeriod->period_type}");
        }

        return DB::transaction(function () use ($monthPeriod) {
            $existing = GlobalBonusMonth::query()->where('month_period_id', $monthPeriod->id)->first();
            if ($existing !== null && $existing->isFinal()) {
                return $existing; // финальный месяц пересчёту не подлежит
            }

            $start = $monthPeriod->starts_at;
            $end = $monthPeriod->ends_at;
            $asOf = $end->subSecond(); // последний миг месяца — ранг as-of

            $policy = $this->policies->forDate($start);
            $config = new GlobalBonusConfig($policy->globalPool());

            $paidBvCents = (int) OrderVolumeSnapshot::query()
                ->where('paid_at', '>=', $start)
                ->where('paid_at', '<', $end)
                ->sum('bv_usd_cents');

            // MF-W5-4: возвраты уменьшают eligible global BV окна (durable — переживает
            // пересчёт draft-месяца, т.к. считается из строк возвратов, а не из снапшотов).
            // Снапшоты заказа immutable (DEC-003); реверс-снапшотов нет, поэтому вычитаем
            // returned_bv возвратов, чьи заказы оплачены в окне месяца.
            $reversedBvCents = (int) OrderReturn::query()
                ->whereIn('order_id', OrderVolumeSnapshot::query()
                    ->where('paid_at', '>=', $start)
                    ->where('paid_at', '<', $end)
                    ->select('order_id'))
                ->sum('returned_bv_cents');

            $globalBvCents = max(0, $paidBvCents - $reversedBvCents);

            $month = GlobalBonusMonth::query()->updateOrCreate(
                ['month_period_id' => $monthPeriod->id],
                [
                    'policy_version_id' => $policy->versionId(),
                    'global_bv_cents' => $globalBvCents,
                    'status' => GlobalBonusMonth::STATUS_DRAFT,
                    'computed_at' => now(),
                ],
            );

            // Чистый пересчёт: сносим дочерние строки draft-месяца (каскад по FK).
            $month->allocations()->delete();
            $month->qualifications()->delete();
            $month->pools()->delete();

            // --- Квалификации + накопление долей по пулам ---
            $sharesByPool = []; // poolStatusCode => [member_id => shares]
            foreach ($config->poolStatusCodes() as $code) {
                $sharesByPool[$code] = [];
            }

            foreach ($this->qualifiedMemberIds($asOf) as $memberId) {
                $ownRank = $this->ranks->rankAsOf($memberId, $asOf);
                if ($ownRank === null || ! $config->isPoolStatus($ownRank)) {
                    continue; // ниже Director — не участвует
                }

                $base = $config->oneShareBaseFor($ownRank); // one_share_pv_min владельца
                $treeMicro = $this->tree->treePvMicro($memberId, $start, $end, $config->includePersonalPv());
                $shares = $base <= 0
                    ? 0
                    : min(intdiv($treeMicro, $base * 1_000_000), $config->maxShares());

                GlobalBonusQualification::query()->create([
                    'global_bonus_month_id' => $month->id,
                    'member_id' => $memberId,
                    'achieved_rank' => $ownRank,
                    'referral_tree_pv' => $this->tree->microToDecimalString($treeMicro),
                    'base_pv' => $this->tree->microToDecimalString($base * 1_000_000),
                    'max_shares' => $config->maxShares(),
                    'shares' => $shares,
                    'calculated_at' => now(),
                ]);

                if ($shares <= 0) {
                    continue;
                }
                foreach ($config->poolsAtOrBelow($ownRank) as $poolCode) {
                    $sharesByPool[$poolCode][$memberId] = ($sharesByPool[$poolCode][$memberId] ?? 0) + $shares;
                }
            }

            // --- Пулы + аллокация ---
            foreach ($config->poolStatusCodes() as $poolCode) {
                $rateBps = $config->rateBpsFor($poolCode);
                $poolAmount = intdiv($globalBvCents * $rateBps, 10000);
                $memberShares = $sharesByPool[$poolCode];
                ksort($memberShares); // детерминизм по member_id ASC
                $totalShares = array_sum($memberShares);

                $pool = GlobalBonusPool::query()->create([
                    'global_bonus_month_id' => $month->id,
                    'pool_rank' => $config->poolRankFor($poolCode),
                    'rate_bps' => $rateBps,
                    'pool_amount_cents' => $poolAmount,
                    'total_shares' => $totalShares,
                    'allocated_cents' => 0,
                    'unallocated_cents' => 0,
                    'unallocated_reason' => null,
                ]);

                $this->allocatePool($month, $pool, $poolAmount, $memberShares, $config->memberCapBp());
            }

            return $month->refresh();
        });
    }

    /**
     * Финализировать месяц (status=final) — вызывается ПОСЛЕ калибровки T11. Идемпотентно.
     *
     * MF-W3-1: финализация — единственная точка фиксации месячной квалификации, поэтому
     * ровно здесь дёргается хук наград T10 `onGlobalQualificationCompleted` — по одному
     * разу на каждого участника, закрывшего квалификацию (shares≥1). Переход draft→final
     * происходит один раз (повторный finalize отсекается early-return ВЫШЕ этого места),
     * значит хук НЕ вызывается на draft-пересчётах allocateForMonth и не задваивается на
     * повторной финализации. T10 сам решает, порождает ли транш (только ранг VP). monthKey
     * строго 'YYYY-MM' из starts_at периода (MF-W3-2) — совпадает с идемпотентной сверкой
     * T10 (`trigger_ref === monthKey`). Всё в одной транзакции: либо месяц финализирован И
     * все хуки отработали, либо откат.
     */
    public function finalizeMonth(CalcPeriod $monthPeriod): ?GlobalBonusMonth
    {
        return DB::transaction(function () use ($monthPeriod) {
            $month = GlobalBonusMonth::query()->where('month_period_id', $monthPeriod->id)->first();
            if ($month === null || $month->isFinal()) {
                return $month;
            }

            $month->update([
                'status' => GlobalBonusMonth::STATUS_FINAL,
                'finalized_at' => now(),
            ]);

            $monthKey = $monthPeriod->starts_at->format('Y-m');
            $qualifiedMemberIds = $month->qualifications()
                ->where('shares', '>=', 1)
                ->orderBy('member_id')
                ->pluck('member_id');
            foreach ($qualifiedMemberIds as $memberId) {
                $this->awardHook->onGlobalQualificationCompleted((int) $memberId, $monthKey);
            }

            return $month->refresh();
        });
    }

    /**
     * Аллокация одного пула: largest-remainder ДО капа (DEC-035) → кап 25% (DEC-034) →
     * остаток → UNALLOCATED. Инвариант: Σ capped(member) + unallocated == poolAmount.
     *
     * @param array<int,int> $memberShares member_id => shares (>0), уже ksort по member_id
     */
    private function allocatePool(
        GlobalBonusMonth $month,
        GlobalBonusPool $pool,
        int $poolAmount,
        array $memberShares,
        int $memberCapBp,
    ): void {
        $denominator = array_sum($memberShares);

        if ($denominator === 0 || $poolAmount === 0) {
            // Пустой пул (нет долей) ЛИБО нулевой пул (нет BV) — всё компании.
            if ($poolAmount > 0) {
                GlobalBonusAllocation::query()->create([
                    'global_bonus_month_id' => $month->id,
                    'pool_id' => $pool->id,
                    'member_id' => null,
                    'kind' => GlobalBonusAllocation::KIND_UNALLOCATED,
                    'shares' => 0,
                    'raw_cents' => $poolAmount,
                    'capped_cents' => $poolAmount,
                    'final_cents' => $poolAmount,
                    'status' => GlobalBonusAllocation::STATUS_ACCRUED,
                ]);
            }
            $pool->update([
                'allocated_cents' => 0,
                'unallocated_cents' => $poolAmount,
                'unallocated_reason' => $poolAmount > 0 ? GlobalBonusPool::REASON_EMPTY_POOL : null,
            ]);

            return;
        }

        // Largest-remainder в центах: floor + раздача остатка по наибольшим дробям.
        $rawByMember = $this->largestRemainder($poolAmount, $memberShares);

        // Кап 25% пула на участника (DEC-034), floor.
        $cap = intdiv($poolAmount * $memberCapBp, 10000);
        $allocated = 0;
        $capped = false;
        foreach ($memberShares as $memberId => $shares) {
            $raw = $rawByMember[$memberId];
            $cappedCents = min($raw, $cap);
            if ($cappedCents < $raw) {
                $capped = true;
            }
            $allocated += $cappedCents;

            GlobalBonusAllocation::query()->create([
                'global_bonus_month_id' => $month->id,
                'pool_id' => $pool->id,
                'member_id' => $memberId,
                'kind' => GlobalBonusAllocation::KIND_MEMBER,
                'shares' => $shares,
                'raw_cents' => $raw,
                'capped_cents' => $cappedCents,
                'final_cents' => $cappedCents, // T11 перезапишет
                'status' => GlobalBonusAllocation::STATUS_ACCRUED,
            ]);
        }

        $unallocated = $poolAmount - $allocated;
        if ($unallocated > 0) {
            GlobalBonusAllocation::query()->create([
                'global_bonus_month_id' => $month->id,
                'pool_id' => $pool->id,
                'member_id' => null,
                'kind' => GlobalBonusAllocation::KIND_UNALLOCATED,
                'shares' => 0,
                'raw_cents' => $unallocated,
                'capped_cents' => $unallocated,
                'final_cents' => $unallocated,
                'status' => GlobalBonusAllocation::STATUS_ACCRUED,
            ]);
        }

        $pool->update([
            'allocated_cents' => $allocated,
            'unallocated_cents' => $unallocated,
            'unallocated_reason' => $unallocated > 0 ? GlobalBonusPool::REASON_CAP_REMAINDER : null,
        ]);
    }

    /**
     * Точное распределение $poolAmount центов пропорционально долям методом наибольшего
     * остатка: Σ результат == $poolAmount ТОЧНО. Ничьи по остатку — по member_id ASC.
     *
     * @param array<int,int> $memberShares member_id => shares (>0)
     * @return array<int,int> member_id => raw_cents
     */
    private function largestRemainder(int $poolAmount, array $memberShares): array
    {
        $denominator = array_sum($memberShares);
        $floor = [];
        $remainder = [];
        $distributed = 0;
        foreach ($memberShares as $memberId => $shares) {
            $num = $poolAmount * $shares;
            $floor[$memberId] = intdiv($num, $denominator);
            $remainder[$memberId] = $num % $denominator;
            $distributed += $floor[$memberId];
        }

        $leftover = $poolAmount - $distributed; // 0 <= leftover < count(members)

        // Кандидаты на +1 цент: по убыванию остатка, при равенстве — по member_id ASC.
        $order = array_keys($memberShares);
        usort($order, function (int $a, int $b) use ($remainder) {
            return $remainder[$b] <=> $remainder[$a] ?: $a <=> $b;
        });
        for ($i = 0; $i < $leftover; $i++) {
            $floor[$order[$i]] += 1;
        }

        return $floor;
    }

    /** id участников с достигнутым (as-of) рангом >= Director. */
    private function qualifiedMemberIds(\DateTimeInterface $asOf): array
    {
        $directorOrdinal = StatusCode::DIRECTOR->ordinal();

        return DB::table('v2_rank_history')
            ->where('rank_ordinal', '>=', $directorOrdinal)
            ->where('achieved_at', '<=', $asOf)
            ->distinct()
            ->orderBy('member_id')
            ->pluck('member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
