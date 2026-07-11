<?php

namespace Modules\Calculator\V2\Services\Status;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PvLotAnnulmentInterface;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Models\RankHistory;
use Modules\Calculator\V2\Services\Volume\ActivationLockGuard;
use Modules\Calculator\V2\Services\Volume\BranchStatsService;

/**
 * T05: жизненный цикл CLIENT -> CONSULTANT (BR-REG-004 / CAL-GRACE-001):
 *  - первая оплата >= 100 PV одним заказом => state=client, grace 30 дней;
 *  - дедлайн = конец 30-го календарного дня 23:59:59 Asia/Almaty, в БД — UTC
 *    (DEC-006/DEC-026 вариант B: 05.07 + 30 дней = конец 04.08 включительно);
 *  - grace-накопление: лоты, где клиент — владелец, ставятся в state=grace_held
 *    (матчинг T03 их не потребляет — деньги CLIENT не платятся);
 *  - личный реферал с оплатой >= 100 PV до дедлайна включительно => CONSULTANT,
 *    grace_held-лоты освобождаются (PV сохраняются и участвуют в расчётах);
 *  - просрочка => state=grace_expired + необратимое аннулирование grace-PV
 *    (PvLotAnnulmentInterface); реферал ПОСЛЕ дедлайна всё равно даёт CONSULTANT
 *    (лестница продолжается), но аннулированные PV не восстанавливаются —
 *    «объёмы считаются только после появления реферала» (BR-REG-004).
 *
 * Все мутации — под ACTIVATION_LOCK оркестратора (оплата/скан): assertLockHeld.
 */
class ClientLifecycleService
{
    /** Дедлайн grace считается в календарных днях Алматы (DEC-006/DEC-026). */
    public const GRACE_TZ = 'Asia/Almaty';

    public function __construct(
        private readonly ActivationLockGuard $lockGuard,
        private readonly PvLotAnnulmentInterface $annulment,
        private readonly BranchStatsService $branchStats,
    ) {
    }

    /**
     * Оплаченный заказ покупателя: если это первая квалифицирующая покупка
     * (>= personal_purchase_pv_min одним заказом) — участник становится CLIENT.
     *
     * @return bool участник ТОЛЬКО ЧТО стал CLIENT (для реферального хука спонсора)
     */
    public function onQualifyingOrderPaid(int $memberId, string $orderPv, CarbonImmutable $paidAt, PolicyV2 $policy): bool
    {
        $this->lockGuard->assertLockHeld();

        $state = $this->stateRow($memberId);
        if ($state->state !== PartnerState::STATE_NONE) {
            return false; // уже CLIENT или выше — идемпотентный no-op
        }

        $minPv = (int) $policy->statusByCode(StatusCode::CLIENT)->personalPurchasePvMin;
        if (bccomp($orderPv, (string) $minPv, 6) < 0) {
            return false; // заказ не квалифицирует (например, < 100 PV)
        }

        $state->state = PartnerState::STATE_CLIENT;
        $state->client_achieved_at = $paidAt;
        $state->grace_started_at = $paidAt;
        $state->grace_expires_at = $this->graceDeadline($paidAt, $policy->graceClientToConsultantDays());
        $state->save();

        $this->recordRank($memberId, StatusCode::CLIENT, $paidAt, $policy, null);

        // Grace-накопление: существующие свободные лоты клиента — на hold
        // (спилловер/покупки даунлайна могли создать их до активации).
        PvLot::query()
            ->where('owner_member_id', $memberId)
            ->where('state', PvLot::STATE_FREE)
            ->update(['state' => PvLot::STATE_GRACE_HELD, 'updated_at' => now()]);

        // У участника УЖЕ есть квалифицированный личный реферал (реферал активировался
        // раньше спонсора) — grace выполняется мгновенно.
        if ($this->qualifiedL1Referrals($memberId, $paidAt) >= 1) {
            $this->succeedGrace($state, $paidAt, $policy);
        }

        return true;
    }

    /**
     * Лоты свежеоплаченного заказа, владельцы которых сидят в grace (state=client,
     * исход не решён) — в grace_held, чтобы матчинг T03 их не потребил до исхода.
     * Вызывается StatusesStep ПОСЛЕ VolumeCaptureStep на каждом заказе.
     */
    public function holdIncomingLotsForGraceClients(int $orderId): void
    {
        $this->lockGuard->assertLockHeld();

        PvLot::query()
            ->where('origin_order_id', $orderId)
            ->where('state', PvLot::STATE_FREE)
            ->whereIn('owner_member_id', function ($q) {
                $q->select('member_id')
                    ->from('v2_partner_states')
                    ->where('state', PartnerState::STATE_CLIENT)
                    ->whereNull('grace_outcome');
            })
            ->update(['state' => PvLot::STATE_GRACE_HELD, 'updated_at' => now()]);
    }

    /**
     * Личный реферал спонсора стал CLIENT (оплатил квалифицирующий заказ >= 100 PV):
     * спонсор-CLIENT в grace (включительно по дедлайну) -> CONSULTANT с сохранением PV;
     * спонсор после просрочки -> CONSULTANT без восстановления аннулированного.
     */
    public function onPersonalReferralActivated(int $sponsorId, int $referralId, CarbonImmutable $paidAt, PolicyV2 $policy): void
    {
        $this->lockGuard->assertLockHeld();

        $state = $this->stateRow($sponsorId);

        if ($state->state === PartnerState::STATE_CLIENT && $state->grace_outcome === null) {
            if ($state->grace_expires_at !== null && $paidAt->greaterThan($state->grace_expires_at)) {
                // Дедлайн уже прошёл, а сканер ещё не успел: сперва фиксируем просрочку
                // (аннулирование), затем продолжаем лестницу новым CONSULTANT.
                $this->expireGrace($sponsorId);
                $state->refresh();
            } else {
                $this->succeedGrace($state, $paidAt, $policy);

                return;
            }
        }

        if ($state->state === PartnerState::STATE_GRACE_EXPIRED) {
            $state->state = PartnerState::STATE_CONSULTANT;
            $state->save();
            $this->recordRank($sponsorId, StatusCode::CONSULTANT, $paidAt, $policy, null);
        }
        // none (спонсор ещё не активирован) — реферал будет учтён при его активации;
        // consultant и выше — no-op.
    }

    /**
     * Идемпотентная фиксация просрочки grace (сканер / ленивый вызов):
     * state=grace_expired, grace_outcome=annulled + необратимое аннулирование PV
     * grace-периода через PvLotAnnulmentInterface (ровно один раз на участника).
     *
     * @return bool просрочка зафиксирована ЭТИМ вызовом
     */
    public function expireGrace(int $memberId): bool
    {
        $this->lockGuard->assertLockHeld();

        $state = PartnerState::query()->whereKey($memberId)->lockForUpdate()->first();
        if ($state === null
            || $state->state !== PartnerState::STATE_CLIENT
            || $state->grace_outcome !== null
            || $state->grace_expires_at === null
            || $state->grace_expires_at->isFuture()) {
            return false;
        }

        $this->annulment->annulBeneficiaryLots(
            $memberId,
            $state->grace_expires_at,
            'client_grace_expired',
            sprintf('grace:%d:%s', $memberId, $state->grace_expires_at->format('YmdHis')),
        );

        $state->state = PartnerState::STATE_GRACE_EXPIRED;
        $state->grace_outcome = PartnerState::OUTCOME_ANNULLED;
        $state->grace_annulled_at = now();
        $state->save();

        return true;
    }

    /** Кол-во квалифицированных личных рефералов 1-й линии (DEC-021) на момент $asOf. */
    public function qualifiedL1Referrals(int $memberId, CarbonInterface $asOf): int
    {
        return DB::table('members')
            ->join('v2_partner_states', 'v2_partner_states.member_id', '=', 'members.id')
            ->where('members.sponsor_id', $memberId)
            ->whereNotNull('v2_partner_states.client_achieved_at')
            ->where('v2_partner_states.client_achieved_at', '<=', $asOf)
            ->count();
    }

    /** Grace выполнен: CONSULTANT, PV сохраняются (grace_held -> free). */
    private function succeedGrace(PartnerState $state, CarbonImmutable $at, PolicyV2 $policy): void
    {
        $state->state = PartnerState::STATE_CONSULTANT;
        $state->grace_outcome = PartnerState::OUTCOME_CONSULTANT;
        $state->save();

        $this->recordRank($state->member_id, StatusCode::CONSULTANT, $at, $policy, null);

        $released = PvLot::query()
            ->where('owner_member_id', $state->member_id)
            ->where('state', PvLot::STATE_GRACE_HELD)
            ->update(['state' => PvLot::STATE_FREE, 'updated_at' => now()]);
        if ($released > 0) {
            $this->branchStats->recompute($state->member_id);
        }
    }

    /**
     * Монотонная запись ранга жизненного цикла (CLIENT/CONSULTANT): insertOrIgnore
     * по unique(member_id, rank_code) + подъём current_rank_code (никогда вниз).
     */
    private function recordRank(int $memberId, StatusCode $rank, CarbonImmutable $at, PolicyV2 $policy, ?string $evaluationId): void
    {
        DB::table('v2_rank_history')->insertOrIgnore([
            'member_id' => $memberId,
            'rank_code' => $rank->value,
            'rank_ordinal' => $rank->ordinal(),
            'achieved_at' => $at,
            'evaluation_id' => $evaluationId,
            'policy_version_id' => $policy->versionId(),
            'created_at' => now(),
        ]);

        $state = $this->stateRow($memberId);
        $currentOrdinal = $state->current_rank_code === null
            ? -1
            : StatusCode::from($state->current_rank_code)->ordinal();
        if ($rank->ordinal() > $currentOrdinal) {
            $state->current_rank_code = $rank->value;
            $state->save();
        }
    }

    /** Дедлайн grace: конец (start + N дней)-го календарного дня в Asia/Almaty, в UTC. */
    public function graceDeadline(CarbonImmutable $start, int $graceDays): CarbonImmutable
    {
        return $start->setTimezone(self::GRACE_TZ)
            ->addDays($graceDays)
            ->endOfDay()
            ->utc();
    }

    /** Строка состояния участника (create-on-first-touch, state=none). */
    public function stateRow(int $memberId): PartnerState
    {
        return PartnerState::query()->firstOrCreate(
            ['member_id' => $memberId],
            ['state' => PartnerState::STATE_NONE, 'personal_pv_total' => '0'],
        );
    }
}
