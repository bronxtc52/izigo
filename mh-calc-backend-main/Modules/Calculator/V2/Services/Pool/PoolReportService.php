<?php

namespace Modules\Calculator\V2\Services\Pool;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\PoolCalibration;
use Modules\Calculator\V2\Models\PoolCalibrationItem;

/**
 * T11: отчёт по 60%-калибровке периода (API для страницы T13). Читает committed-строку
 * v2_pool_calibrations + items. Реферальная — отдельной строкой БЕЗ factor (MF-W3-3):
 * gross == calibrated, factor не применяется (worked example и прозрачность отчёта).
 */
class PoolReportService
{
    /** Список committed-калибровок (новые сверху). */
    public function list(int $limit = 200): array
    {
        return PoolCalibration::query()
            ->where('status', PoolCalibration::STATUS_COMMITTED)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (PoolCalibration $c) => $this->summary($c))
            ->all();
    }

    /** committed-калибровка месяца по коду 'YYYY-MM' или null. */
    public function findCommitted(string $monthCode): ?PoolCalibration
    {
        $period = CalcPeriod::query()
            ->where('period_type', CalcPeriod::TYPE_MONTH)
            ->where('code', $monthCode)
            ->first();
        if ($period === null) {
            return null;
        }

        return PoolCalibration::query()
            ->where('period_id', $period->id)
            ->where('status', PoolCalibration::STATUS_COMMITTED)
            ->first();
    }

    /** Полный отчёт месяца: заголовок + разбивка по видам бонусов. */
    public function report(PoolCalibration $cal): array
    {
        return $this->summary($cal) + [
            'breakdown' => [
                'structure' => [
                    'after_caps_cents' => $cal->structure_after_caps_cents,
                    'calibrated_cents' => $this->kindCalibrated($cal, PoolCalibrationItem::KIND_STRUCTURE),
                    'retained_cents' => $this->kindRetained($cal, PoolCalibrationItem::KIND_STRUCTURE),
                    'in_numerator' => true,
                    'applied_by' => 'T02_ns_os_transfer', // структурную НС→ОС постит T04/T02
                ],
                'global' => [
                    'after_caps_cents' => $cal->global_after_caps_cents,
                    'calibrated_cents' => $this->kindCalibrated($cal, PoolCalibrationItem::KIND_GLOBAL),
                    'retained_cents' => $this->kindRetained($cal, PoolCalibrationItem::KIND_GLOBAL),
                    'in_numerator' => true,
                    'applied_by' => 'T11_final_cents',
                ],
                'referral' => [
                    'gross_cents' => $cal->referral_gross_cents,
                    'calibrated_cents' => $cal->referral_gross_cents, // net == gross (не калибруется)
                    'retained_cents' => 0,
                    'in_numerator' => false, // MF-W3-3: вне 60%-пула
                    'factor_applied' => false,
                ],
            ],
        ];
    }

    /**
     * Постраничный per-member drill-down items месяца (structure + global).
     *
     * @return LengthAwarePaginator<PoolCalibrationItem>
     */
    public function membersPage(PoolCalibration $cal, int $perPage = 50): LengthAwarePaginator
    {
        return $cal->items()
            ->orderBy('member_id')
            ->orderBy('bonus_kind')
            ->orderBy('source_ref')
            ->paginate($perPage);
    }

    private function summary(PoolCalibration $cal): array
    {
        return [
            'id' => $cal->id,
            'period_id' => $cal->period_id,
            'month' => $cal->month,
            'run_version' => $cal->run_version,
            'status' => $cal->status,
            'policy_version_id' => $cal->policy_version_id,
            'pool_rate_bps' => $cal->pool_rate_bps,
            'base_bv_cents' => $cal->base_bv_cents,
            'pool_cap_cents' => $cal->pool_cap_cents,
            'structure_after_caps_cents' => $cal->structure_after_caps_cents,
            'global_after_caps_cents' => $cal->global_after_caps_cents,
            'referral_gross_cents' => $cal->referral_gross_cents,
            'total_after_caps_cents' => $cal->total_after_caps_cents,
            'factor_bps' => $cal->factor_bps,
            'scaled_total_cents' => $cal->scaled_total_cents,
            'company_retained_cents' => $cal->company_retained_cents,
            'committed_at' => $cal->committed_at?->toIso8601ZuluString(),
        ];
    }

    private function kindCalibrated(PoolCalibration $cal, string $kind): int
    {
        return (int) $cal->items()->where('bonus_kind', $kind)->sum('calibrated_cents');
    }

    private function kindRetained(PoolCalibration $cal, string $kind): int
    {
        return (int) $cal->items()->where('bonus_kind', $kind)->sum('retained_cents');
    }
}
