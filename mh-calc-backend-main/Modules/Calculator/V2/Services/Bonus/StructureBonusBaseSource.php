<?php

namespace Modules\Calculator\V2\Services\Bonus;

use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\StructureBonus;

/**
 * T08 — адаптер базы лидерского поверх таблицы структурной премии T06
 * (v2_leadership: net_cents = сумма ПОСЛЕ капов и 60%-калибровки, DEC-029). Reversed
 * и нулевые строки исключаются (сгоревшая капом премия базы не порождает).
 *
 * Month-прогон (штатный, после калибровки T11): строки ОБОИХ half-month окон месяца
 * матчатся по accrual_month = код месяца ('YYYY-MM'; StructureBonus.accrual_month =
 * substr(period.code,0,7)). Half-month/иной прогон (диагностика): строки по period_id.
 * net_cents на момент чтения уже калиброван (шаг лидерского в пайплайне закрытия
 * месяца идёт СТРОГО после шага 60%-пула T11 — контракт DEC-053).
 */
class StructureBonusBaseSource implements LeadershipBaseSourceInterface
{
    public function baseRowsForPeriod(CalcPeriod $period): array
    {
        $query = StructureBonus::query()
            ->where('status', '!=', StructureBonus::STATUS_REVERSED)
            ->where('net_cents', '>', 0);

        if ($period->period_type === CalcPeriod::TYPE_MONTH) {
            $query->where('accrual_month', $period->code);
        } else {
            $query->where('period_id', $period->id);
        }

        return $query
            ->orderBy('id')
            ->get(['id', 'member_id', 'net_cents'])
            ->map(fn (StructureBonus $r) => [
                'id' => (int) $r->id,
                'member_id' => (int) $r->member_id,
                'net_cents' => (int) $r->net_cents,
            ])->all();
    }
}
