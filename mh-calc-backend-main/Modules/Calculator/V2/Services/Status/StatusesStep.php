<?php

namespace Modules\Calculator\V2\Services\Status;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PaidOrderV2Step;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;

/**
 * T05: шаг пайплайна пост-оплаты — статусный слой (тир, CLIENT/grace, лестница).
 * Регистрируется в PaidOrderV2Pipeline ПОСЛЕ VolumeCaptureStep (amendments NTH-4):
 * читает снапшоты/лоты/branch-stats T03, которые тот уже записал. Сам гейтится
 * флагом mh_v2_statuses (deny-by-default) — выключен => ни одной статус-записи.
 * Идемпотентен по заказу (тир — из снапшотов, CLIENT — по state, ранги — по
 * unique rank_history). Работает под advisory-lock активаций (взят markPaid).
 */
class StatusesStep implements PaidOrderV2Step
{
    public const FLAG = 'mh_v2_statuses';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly PolicyVersionResolver $policyResolver,
        private readonly TierService $tiers,
        private readonly ClientLifecycleService $lifecycle,
        private readonly RankEvaluationService $ranks,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return;
        }

        // Снапшоты заказа (owner volume-слоя): покупатель + PV + момент оплаты.
        $rows = DB::table('v2_order_volume_snapshots')->where('order_id', $orderId)->get();
        if ($rows->isEmpty()) {
            return; // volume-слой не заснял заказ (флаг volumes OFF) — статусам нечего считать
        }

        $buyerId = (int) $rows->first()->member_id;
        $paidAt = CarbonImmutable::parse($rows->first()->paid_at);
        $orderPv = $this->sumPv($rows);
        $policy = $this->policyResolver->forDate($paidAt);

        // 1) Тир по накопленному personal PV (идемпотентно из снапшотов).
        $this->tiers->applyPaidOrder($buyerId, $orderId, $paidAt, $policy);

        // 2) Grace-hold входящих лотов, чьи владельцы сидят в grace (не потреблять матчингом).
        $this->lifecycle->holdIncomingLotsForGraceClients($orderId);

        // 3) CLIENT-активация покупателя (первая квалифицирующая покупка >= 100 PV).
        $becameClient = $this->lifecycle->onQualifyingOrderPaid($buyerId, $orderPv, $paidAt, $policy);

        // 4) Реферальный хук спонсору: новый квалифицированный реферал может закрыть grace.
        if ($becameClient) {
            $sponsorId = DB::table('members')->where('id', $buyerId)->value('sponsor_id');
            if ($sponsorId !== null) {
                $this->lifecycle->onPersonalReferralActivated((int) $sponsorId, $buyerId, $paidAt, $policy);
            }
        }

        // 5) Переоценка лестницы покупателя и всего sponsor-аплайна.
        $this->ranks->evaluateAffectedUpline($buyerId, $paidAt, $policy);
    }

    /** @param \Illuminate\Support\Collection $rows */
    private function sumPv($rows): string
    {
        $sum = '0';
        foreach ($rows as $r) {
            $sum = bcadd($sum, (string) $r->pv, 6);
        }

        return $sum;
    }
}
