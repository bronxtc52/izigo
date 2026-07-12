<?php

namespace Modules\Calculator\V2\Services\Status;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\StatusReader;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;

/**
 * T05: read-API статусов/тиров (StatusReader) для контроллеров и соседних задач.
 * КОНТРАКТ ВОЛНЫ: T06/T08/T09 берут ранг as-of отсюда (v2_rank_history), T07 — тир
 * as-of (v2_tier_history); прямое чтение v2_partner_states.current_rank_code
 * соседями запрещено (историю пересчётов half-month это бы сломало).
 */
class StatusReadService implements StatusReader
{
    public function __construct(private readonly TierService $tiers)
    {
    }

    /**
     * Код высшего ранга, достигнутого к моменту $at (null = ранга нет).
     *
     * Резолв = max(rank_ordinal) среди строк с achieved_at <= $at. Корректность
     * опирается на ИНВАРИАНТ МОНОТОННОСТИ (architect, W2 review): achieved_at не
     * убывает с ростом rank_ordinal (высший ранг достигнут не раньше низшего), а сама
     * achieved_at строки неизменна после первой записи. Инвариант ОБЕСПЕЧЕН, не
     * допущение: (1) append-once — unique(member_id, rank_code) (миграция 100300) +
     * insertOrIgnore в recordRank/recordAchievedRanks делают achieved_at каждого ранга
     * immutable (пересчёт half-month не переписывает); (2) upward-only — ранги пишутся
     * непрерывным диапазоном [current+1 … achieved] одним evaluation-таймстампом,
     * current_rank_code только растёт. Коды рангов append-only и не ремапятся между
     * версиями политики => резолв version-agnostic (architect-2).
     */
    public function rankAsOf(int $memberId, \DateTimeInterface $at): ?string
    {
        return DB::table('v2_rank_history')
            ->where('member_id', $memberId)
            ->where('achieved_at', '<=', $at)
            ->orderByDesc('rank_ordinal')
            ->value('rank_code');
    }

    public function tierAsOf(int $memberId, \DateTimeInterface $at): ?string
    {
        return $this->tiers->tierAsOf($memberId, $at);
    }

    /** Текущий срез статуса участника (кабинет/админка). */
    public function currentState(int $memberId): ?PartnerState
    {
        return PartnerState::query()->find($memberId);
    }

    /** Все достигнутые ранги участника (для «пройденных наград» T10 / прогресса T14). */
    public function achievedRanks(int $memberId): array
    {
        return DB::table('v2_rank_history')
            ->where('member_id', $memberId)
            ->orderBy('rank_ordinal')
            ->get(['rank_code', 'rank_ordinal', 'achieved_at', 'evaluation_id'])
            ->map(fn ($r) => [
                'rank_code' => $r->rank_code,
                'rank_ordinal' => (int) $r->rank_ordinal,
                'achieved_at' => $r->achieved_at,
                'evaluation_id' => $r->evaluation_id,
            ])->all();
    }

    /**
     * Прогресс к следующему статусу (для Mini App T14): текущий ранг, следующий код
     * и порог его малой ветки. Чистая проекция из каталога политики.
     *
     * @return array{current_rank:?string, next_rank:?string, next_small_branch_pv_min:?int}
     */
    public function nextStatusProgress(int $memberId, array $statuses): array
    {
        $state = $this->currentState($memberId);
        $currentOrdinal = $state?->current_rank_code === null
            ? -1
            : StatusCode::from($state->current_rank_code)->ordinal();

        $next = null;
        foreach ($statuses as $status) {
            if ($status->ordinal === $currentOrdinal + 1) {
                $next = $status;
                break;
            }
        }

        return [
            'current_rank' => $state?->current_rank_code,
            'next_rank' => $next?->code->value,
            'next_small_branch_pv_min' => $next?->smallBranchPvMin,
        ];
    }
}
