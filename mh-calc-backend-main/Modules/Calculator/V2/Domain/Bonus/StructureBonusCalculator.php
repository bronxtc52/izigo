<?php

namespace Modules\Calculator\V2\Domain\Bonus;

/**
 * T06: чистая математика структурной премии — без I/O, без времени, без БД.
 * Деньги — ТОЛЬКО integer USD-центы, ставка — integer basis points; вся арифметика
 * целочисленная (intdiv), округление — floor на финальной сумме (DEC-002). Никаких
 * неявных коэффициентов (легаси 421.2 запрещён спекой).
 *
 * Формула (CAL-BIN-001):
 *   gross = floor(matched_bv * rate_bps / 10000)
 *   half-month cap применяется к gross ОКНА (H2 стартует с полного half-cap —
 *     неиспользованный лимит H1 НЕ переносится);
 *   monthly-safety: суммарный after_cap двух окон месяца ≤ monthly_cap
 *     (monthly_remaining = monthly_cap − уже_использовано_в_месяце);
 *   after_cap = max(0, min(gross, half_cap, monthly_remaining));
 *   forfeited = gross − after_cap (сматченный сверх капа СГОРАЕТ, решение владельца).
 */
class StructureBonusCalculator
{
    /**
     * @param int $matchedBvCents  BV фактически потреблённых лотов (DEC-016), ≥ 0
     * @param int $rateBps         ставка статуса в basis points (0..10000)
     * @param int $halfCapCents    полумесячный кап статуса (снапшот), ≥ 0
     * @param int $monthlyCapCents месячный кап статуса (снапшот), ≥ 0
     * @param int $monthlyUsedCents уже использованный after_cap ДРУГОГО окна месяца, ≥ 0
     */
    public function compute(
        int $matchedBvCents,
        int $rateBps,
        int $halfCapCents,
        int $monthlyCapCents,
        int $monthlyUsedCents,
    ): StructureBonusResult {
        if ($matchedBvCents < 0 || $rateBps < 0 || $halfCapCents < 0
            || $monthlyCapCents < 0 || $monthlyUsedCents < 0) {
            throw new \InvalidArgumentException('Отрицательные входы структурной премии недопустимы');
        }

        // gross: floor на финальной сумме (целочисленно), без накопления субцентов.
        $gross = intdiv($matchedBvCents * $rateBps, 10000);

        $monthlyRemaining = max(0, $monthlyCapCents - $monthlyUsedCents);

        // after_cap = min трёх границ, не ниже нуля.
        $afterCap = min($gross, $halfCapCents, $monthlyRemaining);
        if ($afterCap < 0) {
            $afterCap = 0;
        }

        $forfeited = $gross - $afterCap; // сматченный сверх капа сгорает

        return new StructureBonusResult(
            grossCents: $gross,
            afterCapCents: $afterCap,
            capRemainingBeforeCents: $monthlyRemaining,
            forfeitedCents: $forfeited,
        );
    }
}
