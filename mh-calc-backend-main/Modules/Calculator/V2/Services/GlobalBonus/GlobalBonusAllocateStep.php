<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;

/**
 * T09 — шаг month-close: расчёт месячного глобального бонуса (пулы/квалификации/
 * аллокации, final_cents=capped). Каскад DEC-053: «raw+капы глобального пула» —
 * ДО 60%-калибровки T11 (T11.order() ∈ (ORDER, GlobalBonusFinalizeStep::ORDER))
 * и ДО финализации месяца. supports только month.
 *
 * Гейт mh_v2_global_bonus (deny-by-default): флаг OFF ⇒ no-op. Preview-прогон
 * (диагностика) ничего не персистит — только BV/пул-суммы в метриках.
 */
class GlobalBonusAllocateStep implements PeriodCloseStep
{
    public const FLAG = 'mh_v2_global_bonus';
    public const ORDER = 300;

    public function __construct(
        private readonly GlobalBonusMonthlyService $service,
        private readonly FeatureFlagService $flags,
    ) {
    }

    public function supports(string $periodType): bool
    {
        return $periodType === CalcPeriod::TYPE_MONTH;
    }

    public function order(): int
    {
        return self::ORDER;
    }

    public function execute(CalcRun $run, CalcPeriod $period): array
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return ['step' => 'global_allocate', 'skipped' => 'flag_off'];
        }
        if ($run->mode === CalcRun::MODE_PREVIEW) {
            return ['step' => 'global_allocate', 'skipped' => 'preview'];
        }

        $month = $this->service->allocateForMonth($period);

        return [
            'step' => 'global_allocate',
            'global_bv_cents' => $month->global_bv_cents,
            'pools' => $month->pools()
                ->orderBy('id')
                ->get(['pool_rank', 'pool_amount_cents', 'total_shares', 'allocated_cents', 'unallocated_cents'])
                ->map(fn ($p) => [
                    'rank' => $p->pool_rank,
                    'pool_cents' => (int) $p->pool_amount_cents,
                    'shares' => (int) $p->total_shares,
                    'allocated_cents' => (int) $p->allocated_cents,
                    'unallocated_cents' => (int) $p->unallocated_cents,
                ])->all(),
        ];
    }
}
