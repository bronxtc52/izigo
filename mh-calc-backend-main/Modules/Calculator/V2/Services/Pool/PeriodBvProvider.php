<?php

namespace Modules\Calculator\V2\Services\Pool;

use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;

/**
 * T11: база 60%-калибровки — BV-оборот месяца = Σ bv_usd_cents PAID-снапшотов заказов
 * (T03 v2_order_volume_snapshots) с paid_at в полуоткрытом окне месяца [starts_at, ends_at)
 * (UTC-границы T04). Идентично global BV в T09 (DEC-031). Возвраты (reversal-снапшоты)
 * вводит T12 — их отрицательные строки уменьшат базу автоматически.
 */
class PeriodBvProvider
{
    public function periodBvCents(CalcPeriod $month): int
    {
        return (int) OrderVolumeSnapshot::query()
            ->where('paid_at', '>=', $month->starts_at)
            ->where('paid_at', '<', $month->ends_at)
            ->sum('bv_usd_cents');
    }
}
