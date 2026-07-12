<?php

namespace Modules\Calculator\V2\Domain\Tier;

use Modules\Calculator\V2\Domain\Policy\TierRule;

/**
 * T05: чистый резолвер тира контракта (CAL-TIER-001) по НАКОПЛЕННОМУ personal PV
 * (решение роадмапа/Гейта A: базис — сумма покупок, не «максимальный тариф»).
 * Decimal-строки + bccomp: 99.99 -> NONE, 100 -> START (границы спеки).
 * Тир НЕ понижается — сравнение с текущим делает вызывающий сервис по ordinal().
 */
class TierResolver
{
    /**
     * @param TierRule[] $tiers из PolicyV2::tiers(), в порядке возрастания min_pv
     * @return ?TierRule высший тир, чей min_pv достигнут; null — ниже START
     */
    public function resolve(array $tiers, string $personalPvTotal): ?TierRule
    {
        $match = null;
        foreach ($tiers as $tier) {
            if (bccomp($personalPvTotal, (string) $tier->minPv, 6) >= 0) {
                $match = $tier;
            }
        }

        return $match;
    }

    /**
     * Порядковый номер тира в каталоге (для правила «тир не понижается»).
     *
     * @param TierRule[] $tiers
     */
    public function ordinal(array $tiers, ?string $tierCode): int
    {
        if ($tierCode === null) {
            return -1;
        }
        foreach (array_values($tiers) as $i => $tier) {
            if ($tier->code === $tierCode) {
                return $i;
            }
        }

        return -1;
    }
}
