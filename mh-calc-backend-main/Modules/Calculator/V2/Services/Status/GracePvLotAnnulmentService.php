<?php

namespace Modules\Calculator\V2\Services\Status;

use Illuminate\Support\Facades\Log;
use Modules\Calculator\V2\Contracts\PvLotAnnulmentInterface;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Services\Volume\ActivationLockGuard;
use Modules\Calculator\V2\Services\Volume\BranchStatsService;

/**
 * T05: аннулирование grace-PV просроченного CLIENT (BR-REG-004 / CAL-GRACE-001
 * annul_all(GRACE_HELD)). Лоты, где участник — владелец (его branch-накопление):
 *  - ВСЕ grace_held-лоты, независимо от occurred_at (architect-3, W2 review:
 *    единый инвариант «grace_expired => ноль grace_held» — grace_held лот в принципе
 *    существует только пока grace не решён, терминальный исход его закрывает;
 *    фильтр по occurred_at на них оставлял бы лоты, попавшие в grace_held у самой
 *    границы дедлайна, застрявшими навсегда);
 *  - free-лоты grace-периода (occurred_at <= until) — страховка на лоты, созданные
 *    до постановки на grace-hold.
 * Для отобранных: pv_available -> pv_reversed, state -> reversed/exhausted; затем
 * recompute branch-stats — lifetime у BranchStatsService/BinaryVolumeReader считается
 * как pv_original − pv_reversed, то есть аннулированное НЕОБРАТИМО выпадает из порогов
 * лестницы.
 *
 * Идемпотентность: повторный вызов не находит лотов с pv_available > 0 (0 строк).
 * Лок: под ACTIVATION_LOCK оркестратора (grace-скан) — assertLockHeld, мутация
 * PV-лотов вне оплаты (дисциплина W1 MF-7).
 */
class GracePvLotAnnulmentService implements PvLotAnnulmentInterface
{
    public function __construct(
        private readonly ActivationLockGuard $lockGuard,
        private readonly BranchStatsService $branchStats,
    ) {
    }

    public function annulBeneficiaryLots(
        int $memberId,
        \DateTimeInterface $until,
        string $reason,
        string $idempotencyKey,
    ): int {
        $this->lockGuard->assertLockHeld();

        $lots = PvLot::query()
            ->where('owner_member_id', $memberId)
            ->where(function ($q) use ($until) {
                // ВСЕ grace_held участника (architect-3) ...
                $q->where('state', PvLot::STATE_GRACE_HELD)
                    // ... плюс free-лоты grace-периода (occurred_at <= until).
                    ->orWhere(function ($q2) use ($until) {
                        $q2->where('state', PvLot::STATE_FREE)
                            ->where('occurred_at', '<=', $until);
                    });
            })
            ->where('pv_available', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $annulled = 0;
        foreach ($lots as $lot) {
            $lot->pv_reversed = bcadd((string) $lot->pv_reversed, (string) $lot->pv_available, 6);
            $lot->pv_available = '0';
            // Инвариант pv_available + pv_matched + pv_reversed = pv_original сохранён.
            $lot->state = bccomp((string) $lot->pv_matched, '0', 6) === 0
                ? PvLot::STATE_REVERSED
                : PvLot::STATE_EXHAUSTED;
            $lot->save();
            $annulled++;
        }

        if ($annulled > 0) {
            $this->branchStats->recompute($memberId);
        }

        Log::info('V2 statuses: grace-аннулирование PV-лотов', [
            'member_id' => $memberId,
            'until' => $until->format(DATE_ATOM),
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'lots_annulled' => $annulled,
        ]);

        return $annulled;
    }
}
