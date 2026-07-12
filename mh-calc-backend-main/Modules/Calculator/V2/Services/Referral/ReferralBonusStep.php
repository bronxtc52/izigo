<?php

namespace Modules\Calculator\V2\Services\Referral;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PaidOrderV2Step;

/**
 * T07: шаг пайплайна пост-оплаты — реферальная премия (amendments NTH-4).
 * Регистрируется в PaidOrderV2Pipeline ПОСЛЕ VolumeCaptureStep (T03) и StatusesStep
 * (T05): нужны снапшоты BV заказа (T03) и актуальный тир получателя (T05). Сам
 * гейтится флагом mh_v2_referral (deny-by-default) — выключен => ни одной записи.
 * Идемпотентен по заказу (UNIQUE(order_id, depth) + ledger idempotency_key).
 * Работает под advisory-lock активаций (взят markPaid).
 */
class ReferralBonusStep implements PaidOrderV2Step
{
    public const FLAG = 'mh_v2_referral';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly ReferralBonusService $referral,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return;
        }

        $this->referral->onOrderPaid($orderId);
    }
}
