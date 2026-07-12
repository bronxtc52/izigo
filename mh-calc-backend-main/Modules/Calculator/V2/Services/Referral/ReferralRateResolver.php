<?php

namespace Modules\Calculator\V2\Services\Referral;

use Modules\Calculator\V2\Contracts\PolicyV2;

/**
 * T07: матрица реферальных ставок {тир получателя}×{глубина} в basis points.
 *
 * КОНТРАКТ T01: ставки живут на ТИРЕ получателя (TierRule::referralL1Bp/referralL2Bp),
 * не в отдельном блоке referral.rates_bps — план T07 §604 предполагал плоский словарь,
 * но T01 реализовал ставки на тирах (см. DefaultPolicyConfig.tiers[].referral_rates_bp);
 * резолвер читает их через PolicyV2::tierByCode(). CAL-REF-001: L1 10% для всех тиров,
 * L2 = START 0% / BUSINESS 5% / ELITE 8%.
 *
 * Тир получателя = null (накопленный personal PV ниже START) → ставка 0 (реферальная не
 * платится до достижения START, решение Гейта A вопрос 2; таблица BR-TIER-001 начинается
 * со START >= 100 PV). Неизвестный тир или глубина вне 1..2 → fail-fast исключение
 * (не молчаливый 0 — деньги не теряются тихо).
 */
class ReferralRateResolver
{
    /**
     * Ставка bps для получателя данного тира на данной глубине реферального дерева.
     *
     * @param ?string $beneficiaryTier START|BUSINESS|ELITE|null (null = ниже START)
     * @param int     $depth           1 (прямой реферал) | 2
     */
    public function rateBps(PolicyV2 $policy, ?string $beneficiaryTier, int $depth): int
    {
        if ($depth < 1 || $depth > 2) {
            throw new \DomainException("Реферальная глубина вне 1..2: {$depth}");
        }

        // Ниже START — тира нет, реферальная не начисляется (ставка 0, explain-строка zero_rate).
        if ($beneficiaryTier === null) {
            return 0;
        }

        // tierByCode бросает InvalidArgumentException на неизвестном тире — fail-fast.
        $tier = $policy->tierByCode($beneficiaryTier);

        return $depth === 1 ? $tier->referralL1Bp : $tier->referralL2Bp;
    }
}
