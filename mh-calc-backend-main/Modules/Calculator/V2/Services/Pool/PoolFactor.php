<?php

namespace Modules\Calculator\V2\Services\Pool;

/**
 * T11 — ЯДРО формулы 60%-калибровки (amendments MF-1/2). ЦЕЛОЧИСЛЕННАЯ математика,
 * никакого float в money-path. Immutable value object.
 *
 *   pool_cap_cents = intdiv(base_bv_cents × rate_bps, 10000)
 *   factor_bps     = total_after_caps_cents == 0 ? 10000
 *                    : min(10000, intdiv(pool_cap_cents × 10000, total_after_caps_cents))
 *
 * Только scale-down: factor_bps ∈ [0, 10000], scale-up НЕВОЗМОЖЕН по построению (min с
 * 10000). Применение — floor: paid = intdiv(raw × factor_bps, 10000); Σ выплат ≤ pool_cap.
 * distribute() раздаёт центы округления методом наибольшего остатка так, что
 * Σ paid = intdiv(Σ raw × factor_bps, 10000) ТОЧНО (для сумм, которыми владеет T11 сам —
 * глобальный final_cents). Ни цента не теряется: retained = raw − paid удерживается компанией.
 */
final class PoolFactor
{
    public const FULL_BPS = 10000;

    private function __construct(
        public readonly int $factorBps,
        public readonly int $poolCapCents,
        public readonly int $totalAfterCapsCents,
        public readonly int $baseBvCents,
        public readonly int $rateBps,
    ) {
    }

    public static function forPeriod(int $baseBvCents, int $rateBps, int $totalAfterCapsCents): self
    {
        if ($baseBvCents < 0 || $rateBps < 0 || $totalAfterCapsCents < 0) {
            throw new \InvalidArgumentException('PoolFactor: отрицательные входы недопустимы (integer USD-центы/bps).');
        }

        $poolCap = intdiv($baseBvCents * $rateBps, self::FULL_BPS);
        $factor = $totalAfterCapsCents === 0
            ? self::FULL_BPS
            : min(self::FULL_BPS, intdiv($poolCap * self::FULL_BPS, $totalAfterCapsCents));

        return new self($factor, $poolCap, $totalAfterCapsCents, $baseBvCents, $rateBps);
    }

    public function isFull(): bool
    {
        return $this->factorBps >= self::FULL_BPS;
    }

    /** Одна сумма после factor (floor). Зеркалит per-member перевод НС→ОС в T02. */
    public function scale(int $rawCents): int
    {
        if ($rawCents < 0) {
            throw new \InvalidArgumentException('PoolFactor::scale: raw < 0.');
        }

        return intdiv($rawCents * $this->factorBps, self::FULL_BPS);
    }

    /**
     * Раздать factor по набору сумм так, что Σ paid = intdiv(Σ raw × factor, 10000).
     * Метод наибольшего остатка; ничьи — по ключу ASC (детерминизм). Каждый paid ≤ raw.
     *
     * @param array<int|string,int> $rawByKey
     * @return array<int|string,int> тот же порядок ключей, paid по каждому
     */
    public function distribute(array $rawByKey): array
    {
        if ($rawByKey === []) {
            return [];
        }

        $total = 0;
        foreach ($rawByKey as $raw) {
            if ($raw < 0) {
                throw new \InvalidArgumentException('PoolFactor::distribute: raw < 0.');
            }
            $total += $raw;
        }

        $target = intdiv($total * $this->factorBps, self::FULL_BPS);

        $floor = [];
        $remainder = [];
        $distributed = 0;
        foreach ($rawByKey as $key => $raw) {
            $num = $raw * $this->factorBps;
            $floor[$key] = intdiv($num, self::FULL_BPS);
            $remainder[$key] = $num % self::FULL_BPS;
            $distributed += $floor[$key];
        }

        $leftover = $target - $distributed; // 0 <= leftover < count(rawByKey)

        // +1 цент по убыванию остатка, при равенстве — по ключу ASC.
        $order = array_keys($rawByKey);
        usort($order, static fn ($a, $b) => ($remainder[$b] <=> $remainder[$a]) ?: ($a <=> $b));
        for ($i = 0; $i < $leftover; $i++) {
            $floor[$order[$i]] += 1;
        }

        return $floor;
    }
}
