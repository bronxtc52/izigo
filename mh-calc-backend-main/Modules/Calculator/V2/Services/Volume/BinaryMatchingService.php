<?php

namespace Modules\Calculator\V2\Services\Volume;

use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Domain\Volume\LotMatcher;
use Modules\Calculator\V2\Domain\Volume\LotSlice;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\PvLot;

/**
 * T03: персист-обвязка чистого LotMatcher — прогон матчинга участника.
 *
 * Дисциплина конкурентности: free-лоты берутся SELECT … FOR UPDATE в FIFO-порядке
 * (два конкурентных воркера не потребят один лот дважды); идемпотентность прогона —
 * UNIQUE(member_id, period_key, run_uuid): повтор с тем же ключом = no-op (возврат
 * существующего матча). Carryover — просто остаток pv_available (бессрочный,
 * DEC-018, ничего не сгорает). Денег здесь нет: ставки/капы/НС-постинг — T06.
 */
class BinaryMatchingService
{
    public function __construct(
        private readonly LotMatcher $matcher,
        private readonly BranchStatsService $branchStats,
        private readonly ActivationLockGuard $lockGuard,
    ) {
    }

    /**
     * Прогнать матчинг участника: min(Σ free L, Σ free R) на лотах с
     * occurred_at < cutoff. Нулевой результат тоже персистится (explainability).
     *
     * Дисциплина локов (ревью W1 MF-7, amendments #5): advisory-lock активаций
     * берёт внешний оркестратор (закрытие периода T04 / админ-триггер / возврат),
     * сервис только проверяет — иначе ручной матчинг конкурирует с инжестом
     * лотов оплаты и V1-пересчётом сети.
     */
    public function runMatching(
        int $memberId,
        DateTimeInterface $cutoff,
        string $periodKey,
        string $runUuid,
    ): BinaryMatch {
        $this->lockGuard->assertLockHeld();

        $existing = BinaryMatch::query()
            ->where('member_id', $memberId)
            ->where('period_key', $periodKey)
            ->where('run_uuid', $runUuid)
            ->first();
        if ($existing !== null) {
            return $existing; // идемпотентный повтор
        }

        try {
            $match = DB::transaction(function () use ($memberId, $cutoff, $periodKey, $runUuid) {
                // MF-1b (W2 review) / BR-REG-004: изоляция grace. Лоты владельца, который
                // ещё не активировался (CLIENT в grace или просроченный grace_expired),
                // НЕ матчабельны — их PV не засчитывается в пары, пока владелец не станет
                // CONSULTANT. Пост-дедлайновые FREE-лоты просроченного клиента остаются
                // FREE (PV не теряется), но здесь пропускаются; как только владелец
                // активируется (succeedGrace ИЛИ grace_expired->CONSULTANT), те же лоты
                // легитимно матчатся следующим прогоном. none/consultant+ — как раньше.
                $lots = $this->ownerMatchable($memberId)
                    ? PvLot::query()
                        ->where('owner_member_id', $memberId)
                        ->where('state', PvLot::STATE_FREE)
                        ->where('pv_available', '>', 0)
                        // Полуоткрытое окно [start, cutoff): лот ровно в cutoff — уже следующий период.
                        ->where('occurred_at', '<', $cutoff)
                        ->orderBy('occurred_at')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                    : collect();

                // Остаток BV лота = original − уже потреблённое прошлыми матчами:
                // за жизнь лота аллокации сходятся ровно в bv_original.
                $consumedBv = DB::table('v2_pv_lot_allocations')
                    ->whereIn('pv_lot_id', $lots->pluck('id')->all())
                    ->groupBy('pv_lot_id')
                    ->selectRaw('pv_lot_id, SUM(bv_usd_cents_consumed) AS consumed')
                    ->pluck('consumed', 'pv_lot_id');

                $slices = ['left' => [], 'right' => []];
                foreach ($lots as $lot) {
                    $slices[$lot->side][] = new LotSlice(
                        lotId: $lot->id,
                        pvAvailable: $lot->pv_available,
                        bvCentsRemaining: $lot->bv_usd_cents_original - (int) ($consumedBv[$lot->id] ?? 0),
                    );
                }

                $result = $this->matcher->match(
                    $slices[PvLot::SIDE_LEFT] ?? [],
                    $slices[PvLot::SIDE_RIGHT] ?? []
                );

                $match = BinaryMatch::query()->create([
                    'member_id' => $memberId,
                    'period_key' => $periodKey,
                    'run_uuid' => $runUuid,
                    'cutoff_at' => $cutoff,
                    'matched_pv' => $result->matchedPv,
                    'matched_bv_usd_cents' => $result->matchedBvCents,
                    'status' => BinaryMatch::STATUS_PROVISIONAL,
                ]);

                $lotsById = $lots->keyBy('id');
                foreach ($result->consumptions as $consumption) {
                    DB::table('v2_pv_lot_allocations')->insert([
                        'binary_match_id' => $match->id,
                        'pv_lot_id' => $consumption->lotId,
                        'side' => $consumption->side,
                        'pv_consumed' => $consumption->pvConsumed,
                        'bv_usd_cents_consumed' => $consumption->bvCentsConsumed,
                        'created_at' => now(),
                    ]);

                    /** @var PvLot $lot */
                    $lot = $lotsById[$consumption->lotId];
                    $lot->pv_available = bcsub($lot->pv_available, $consumption->pvConsumed, 6);
                    $lot->pv_matched = bcadd($lot->pv_matched, $consumption->pvConsumed, 6);
                    if (bccomp($lot->pv_available, '0', 6) === 0) {
                        $lot->state = PvLot::STATE_EXHAUSTED;
                    }
                    $lot->save();
                }

                return $match;
            });
        } catch (QueryException $e) {
            // Гонка двух воркеров с одинаковым ключом: уникальный индекс отработал —
            // перечитываем чужой результат (лоты не тронуты, транзакция откатилась).
            $match = BinaryMatch::query()
                ->where('member_id', $memberId)
                ->where('period_key', $periodKey)
                ->where('run_uuid', $runUuid)
                ->first();
            if ($match === null) {
                throw $e;
            }

            return $match;
        }

        $this->branchStats->recompute($memberId);

        return $match;
    }

    /**
     * Матчабелен ли владелец: FREE-лоты участника участвуют в матчинге, только если
     * он НЕ сидит в grace-лимбе. Deny-list — client (grace ещё не решён) и grace_expired
     * (просрочка, не активировался). none и consultant+ (а также отсутствие строки статуса,
     * напр. при выключенном флаге mh_v2_statuses) — матчабельны, легаси-поведение T03.
     */
    private function ownerMatchable(int $memberId): bool
    {
        $state = DB::table('v2_partner_states')
            ->where('member_id', $memberId)
            ->value('state');

        return ! in_array($state, [
            PartnerState::STATE_CLIENT,
            PartnerState::STATE_GRACE_EXPIRED,
        ], true);
    }

    /** Финализация периода: все provisional-матчи периода → final (идемпотентно). */
    public function finalizeForPeriod(string $periodKey): int
    {
        return BinaryMatch::query()
            ->where('period_key', $periodKey)
            ->where('status', BinaryMatch::STATUS_PROVISIONAL)
            ->update(['status' => BinaryMatch::STATUS_FINAL]);
    }
}
