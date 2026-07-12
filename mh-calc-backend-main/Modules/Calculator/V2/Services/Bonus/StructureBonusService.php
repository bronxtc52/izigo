<?php

namespace Modules\Calculator\V2\Services\Bonus;

use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Contracts\StatusReader;
use Modules\Calculator\V2\Domain\Bonus\StructureBonusCalculator;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;

/**
 * T06: оркестрация расчёта структурной премии окна half-month (status=calculated).
 *
 * Для КАЖДОГО участника с достигнутым рангом ≥ CONSULTANT (as-of конец окна,
 * StatusReader — v2_rank_history, не current) прогоняем matching T03 (min free L/R,
 * FIFO-потребление лотов с BV-provenance), снапшотим ставку/капы политики и считаем
 * премию чистым калькулятором. CLIENT (ранг < CONSULTANT) НЕ матчится — его лоты
 * остаются free и копятся до достижения ранга (спека / план T06).
 *
 * Матчинг — единственный денежный вход (matched_bv_cents из T03, T06 не пере-выводит
 * BV из PV, amendments #3). run_uuid = 'period:{code}' — канонический прогон окна
 * (идемпотентно по member/period/run_uuid). Сматченный сверх денежного капа PV
 * СГОРАЕТ (решение владельца) — forfeited виден в строке и explanation.
 *
 * Деньги здесь НЕ постятся — это отдельный шаг StructureBonusPostingService
 * (разнесение calculate/post = точка вставки 60%-пула T11 между ними, DEC-053).
 * Вызывается под advisory-lock активаций (взят оркестратором закрытия периода);
 * runMatching сам проверяет удержание лока (ActivationLockGuard).
 */
class StructureBonusService
{
    public function __construct(
        private readonly PolicyVersionResolver $policyResolver,
        private readonly StatusReader $statusReader,
        private readonly BinaryMatchingService $matching,
        private readonly StructureBonusCalculator $calculator,
        private readonly PeriodService $periods,
    ) {
    }

    /**
     * Рассчитать структурную премию окна. Идемпотентно по (period_id, member_id):
     * повтор перезаписывает calculated-строки теми же значениями; posted не трогает.
     *
     * @return array{members:int,eligible:int,gross_cents:int,after_cap_cents:int,forfeited_cents:int}
     */
    public function calculateForPeriod(CalcPeriod $period): array
    {
        // Контракт T04: закрытый период неизменяем. allowClosing=true — шаг работает
        // ВНУТРИ пайплайна (status=closing); standalone-перезапуск на closed отвергается.
        $this->periods->assertOpen($period, allowClosing: true);

        /** @var \Carbon\CarbonImmutable $cutoff */
        $cutoff = $period->ends_at; // правая граница полуоткрытого окна (UTC, контракт T04)
        $accrualMonth = substr($period->code, 0, 7); // 'YYYY-MM' — атрибуция НС (MF-3)
        $runUuid = 'period:' . $period->code; // канонический прогон матчинга окна

        $ownerIds = PvLot::query()
            ->where('occurred_at', '<', $cutoff)
            ->distinct()
            ->orderBy('owner_member_id')
            ->pluck('owner_member_id');

        // Нет объёма — политику не резолвим (пустое закрытие не требует активной версии).
        if ($ownerIds->isEmpty()) {
            return ['members' => 0, 'eligible' => 0, 'gross_cents' => 0, 'after_cap_cents' => 0, 'forfeited_cents' => 0];
        }

        $policy = $this->policyResolver->forDate($cutoff);
        $consultantOrdinal = StatusCode::CONSULTANT->ordinal();

        $eligible = 0;
        $sumGross = 0;
        $sumAfterCap = 0;
        $sumForfeited = 0;

        foreach ($ownerIds as $ownerId) {
            $ownerId = (int) $ownerId;
            $rankCode = $this->statusReader->rankAsOf($ownerId, $cutoff);
            if ($rankCode === null) {
                continue; // ранга ещё нет — не участник структурной премии
            }
            if (StatusCode::from($rankCode)->ordinal() < $consultantOrdinal) {
                continue; // CLIENT — лоты не потребляются, остаются free (спека/план)
            }

            $eligible++;
            $match = $this->matching->runMatching($ownerId, $cutoff, $period->code, $runUuid);

            $result = $this->persistRow($period, $ownerId, $rankCode, $accrualMonth, $policy, $match);
            $sumGross += $result['gross'];
            $sumAfterCap += $result['after_cap'];
            $sumForfeited += $result['forfeited'];
        }

        return [
            'members' => $ownerIds->count(),
            'eligible' => $eligible,
            'gross_cents' => $sumGross,
            'after_cap_cents' => $sumAfterCap,
            'forfeited_cents' => $sumForfeited,
        ];
    }

    /**
     * @return array{gross:int,after_cap:int,forfeited:int}
     */
    private function persistRow(
        CalcPeriod $period,
        int $ownerId,
        string $rankCode,
        string $accrualMonth,
        PolicyV2 $policy,
        BinaryMatch $match,
    ): array {
        $status = $policy->statusByCode($rankCode);
        $rateBps = $status->binaryRateBp;
        $halfCap = $status->halfMonthCapCents;
        $monthlyCap = $status->monthlyCapCents;
        $matchedBv = $match->matched_bv_usd_cents;
        $monthlyUsed = $this->monthlyUsedCents($ownerId, $period->id, $accrualMonth);

        $calc = $this->calculator->compute($matchedBv, $rateBps, $halfCap, $monthlyCap, $monthlyUsed);

        $existing = StructureBonus::query()
            ->where('period_id', $period->id)
            ->where('member_id', $ownerId)
            ->first();

        // Уже проведено (posted) — финализированную строку не переписываем.
        if ($existing !== null && $existing->status === StructureBonus::STATUS_POSTED) {
            return ['gross' => $existing->gross_cents, 'after_cap' => $existing->after_cap_cents, 'forfeited' => $existing->forfeited_cents];
        }

        $allocations = $match->allocations()
            ->get(['pv_lot_id', 'side', 'pv_consumed', 'bv_usd_cents_consumed'])
            ->map(fn ($a) => [
                'pv_lot_id' => (int) $a->pv_lot_id,
                'side' => $a->side,
                'pv_consumed' => $a->pv_consumed,
                'bv_usd_cents_consumed' => (int) $a->bv_usd_cents_consumed,
            ])->all();

        $row = $existing ?? new StructureBonus();
        $row->fill([
            'period_id' => $period->id,
            'member_id' => $ownerId,
            'policy_version_id' => $policy->versionId(),
            'rank_code' => $rankCode,
            'rate_bps' => $rateBps,
            'matched_pv' => $match->matched_pv,
            'matched_bv_cents' => $matchedBv,
            'match_group_id' => $match->id,
            'gross_cents' => $calc->grossCents,
            'half_cap_cents' => $halfCap,
            'monthly_cap_cents' => $monthlyCap,
            'cap_remaining_before_cents' => $calc->capRemainingBeforeCents,
            'after_cap_cents' => $calc->afterCapCents,
            'forfeited_cents' => $calc->forfeitedCents,
            'net_cents' => $calc->afterCapCents, // = after_cap ПОКА; T11 перепишет на калиброванное (after_cap×factor) на month-close ДО лидерского T08 (MF-W4-1, DEC-029)
            'accrual_month' => $accrualMonth,
            'status' => StructureBonus::STATUS_CALCULATED,
            'posting_idempotency_key' => sprintf('v2:structure:%d:%d', $period->id, $ownerId),
            'explanation' => [
                'policy_version_id' => $policy->versionId(),
                'config_hash' => $policy->configHash(),
                'rank_code' => $rankCode,
                'rate_bps' => $rateBps,
                'matched_pv' => $match->matched_pv,
                'matched_bv_cents' => $matchedBv,
                'allocations' => $allocations,
                'gross_cents' => $calc->grossCents,
                'half_cap_cents' => $halfCap,
                'monthly_cap_cents' => $monthlyCap,
                'monthly_used_cents' => $monthlyUsed,
                'cap_remaining_before_cents' => $calc->capRemainingBeforeCents,
                'after_cap_cents' => $calc->afterCapCents,
                'forfeited_cents' => $calc->forfeitedCents,
            ],
        ]);
        $row->save();

        return ['gross' => $calc->grossCents, 'after_cap' => $calc->afterCapCents, 'forfeited' => $calc->forfeitedCents];
    }

    /**
     * Уже использованный в этом месяце after_cap участника (ДРУГИЕ окна месяца).
     * База использования — after_cap (капы применяются ДО 60%-пула, DEC-053), а не net.
     */
    private function monthlyUsedCents(int $memberId, int $currentPeriodId, string $accrualMonth): int
    {
        return (int) StructureBonus::query()
            ->where('member_id', $memberId)
            ->where('accrual_month', $accrualMonth)
            ->where('period_id', '!=', $currentPeriodId)
            ->whereIn('status', [StructureBonus::STATUS_CALCULATED, StructureBonus::STATUS_POSTED])
            ->sum('after_cap_cents');
    }
}
