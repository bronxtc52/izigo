<?php

namespace Modules\Calculator\V2\Domain\Volume;

use InvalidArgumentException;

/**
 * T03: ЧИСТАЯ функция матчинга бинара (зеркало pseudocode CAL-BIN-001, без БД).
 *
 * Вход — свободные лоты сторон УЖЕ в FIFO-порядке (earliest occurred_at, потом id —
 * сортирует вызывающий сервис). Выход:
 *  - matched_pv = min(Σ free L, Σ free R) — decimal(18,6), bcmath;
 *  - потребление FIFO с обеих сторон ровно на matched_pv;
 *  - BV аллокаций: доля от ОСТАТКА BV лота пропорционально потреблённому PV;
 *    целочисленные центы, округление largest-remainder ВНУТРИ стороны — сумма
 *    аллокаций стороны строго равна BV-итогу стороны (центы не теряются);
 *    полностью выпитый лот отдаёт весь остаток BV без округления;
 *  - matched_bv_cents = min(BV потреблённого L, BV потреблённого R) (DEC-016;
 *    легаси-коэффициент 421.2 запрещён — BV только из provenance лотов).
 *
 * Carryover — просто непотреблённый pv_available (бессрочный, DEC-018);
 * матчер его не трогает.
 */
class LotMatcher
{
    public const SIDE_LEFT = 'left';
    public const SIDE_RIGHT = 'right';

    private const SCALE = 6;      // decimal(18,6) PV
    private const BV_SCALE = 12;  // промежуточная точность дробных долей BV

    /**
     * @param LotSlice[] $left  свободные лоты левой стороны в FIFO-порядке
     * @param LotSlice[] $right свободные лоты правой стороны в FIFO-порядке
     */
    public function match(array $left, array $right): MatchResult
    {
        $sumL = $this->sumAvailable($left);
        $sumR = $this->sumAvailable($right);

        $matched = bccomp($sumL, $sumR, self::SCALE) <= 0 ? $sumL : $sumR;

        if (bccomp($matched, '0', self::SCALE) === 0) {
            $reason = match (true) {
                bccomp($sumL, '0', self::SCALE) === 0
                    && bccomp($sumR, '0', self::SCALE) === 0 => 'both_empty',
                bccomp($sumL, '0', self::SCALE) === 0 => 'left_empty',
                default => 'right_empty',
            };

            return new MatchResult('0.000000', 0, 0, 0, [], $reason);
        }

        [$leftConsumptions, $leftBv] = $this->consumeSide($left, $matched, self::SIDE_LEFT);
        [$rightConsumptions, $rightBv] = $this->consumeSide($right, $matched, self::SIDE_RIGHT);

        return new MatchResult(
            matchedPv: bcadd($matched, '0', self::SCALE),
            matchedBvCents: min($leftBv, $rightBv),
            leftBvCentsConsumed: $leftBv,
            rightBvCentsConsumed: $rightBv,
            consumptions: array_merge($leftConsumptions, $rightConsumptions),
        );
    }

    /** @param LotSlice[] $slices */
    private function sumAvailable(array $slices): string
    {
        $sum = '0';
        foreach ($slices as $slice) {
            if (bccomp($slice->pvAvailable, '0', self::SCALE) < 0) {
                throw new InvalidArgumentException("Лот {$slice->lotId}: отрицательный pv_available");
            }
            $sum = bcadd($sum, $slice->pvAvailable, self::SCALE);
        }

        return $sum;
    }

    /**
     * Потребить target PV с одной стороны FIFO и разложить BV largest-remainder.
     *
     * @param LotSlice[] $slices
     * @return array{0: LotConsumption[], 1: int} [консампшены, BV-итог стороны в центах]
     */
    private function consumeSide(array $slices, string $target, string $side): array
    {
        $remaining = $target;
        /** @var array<int, array{slice: LotSlice, take: string, exhausted: bool, exact: string}> $entries */
        $entries = [];

        foreach ($slices as $slice) {
            if (bccomp($remaining, '0', self::SCALE) === 0) {
                break;
            }
            if (bccomp($slice->pvAvailable, '0', self::SCALE) === 0) {
                continue;
            }

            $exhausted = bccomp($slice->pvAvailable, $remaining, self::SCALE) <= 0;
            $take = $exhausted ? $slice->pvAvailable : $remaining;
            $remaining = bcsub($remaining, $take, self::SCALE);

            // Точная (дробная) BV-доля: весь остаток BV при полном выпивании,
            // иначе пропорция от остатка BV по потреблённому PV.
            $exact = $exhausted
                ? bcadd((string) $slice->bvCentsRemaining, '0', self::BV_SCALE)
                : bcdiv(
                    bcmul((string) $slice->bvCentsRemaining, $take, self::BV_SCALE),
                    $slice->pvAvailable,
                    self::BV_SCALE
                );

            $entries[] = ['slice' => $slice, 'take' => $take, 'exhausted' => $exhausted, 'exact' => $exact];
        }

        if (bccomp($remaining, '0', self::SCALE) !== 0) {
            // Вызывающий гарантирует target = min(ΣL, ΣR) ≤ Σ стороны — сюда попадать нельзя.
            throw new InvalidArgumentException("Сторона {$side}: не хватает free PV на target {$target}");
        }

        // BV-итог стороны: floor суммы точных долей (потерянные дробные центы не выдумываем).
        $exactTotal = '0';
        foreach ($entries as $entry) {
            $exactTotal = bcadd($exactTotal, $entry['exact'], self::BV_SCALE);
        }
        $sideBvTotal = $this->bcFloorToInt($exactTotal);

        // Largest-remainder: floor каждой доли, дефицит раздаём по наибольшим дробям
        // (при равенстве — более ранний FIFO-лот).
        $floors = [];
        $fractions = [];
        $floorSum = 0;
        foreach ($entries as $i => $entry) {
            $floor = $this->bcFloorToInt($entry['exact']);
            $floors[$i] = $floor;
            $fractions[$i] = bcsub($entry['exact'], (string) $floor, self::BV_SCALE);
            $floorSum += $floor;
        }

        $deficit = $sideBvTotal - $floorSum;
        if ($deficit > 0) {
            $order = array_keys($entries);
            usort($order, function (int $a, int $b) use ($fractions): int {
                $cmp = bccomp($fractions[$b], $fractions[$a], self::BV_SCALE);

                return $cmp !== 0 ? $cmp : $a <=> $b;
            });
            foreach (array_slice($order, 0, $deficit) as $i) {
                $floors[$i]++;
            }
        }

        $consumptions = [];
        foreach ($entries as $i => $entry) {
            $consumptions[] = new LotConsumption(
                lotId: $entry['slice']->lotId,
                side: $side,
                pvConsumed: bcadd($entry['take'], '0', self::SCALE),
                bvCentsConsumed: $floors[$i],
                exhausted: $entry['exhausted'],
            );
        }

        return [$consumptions, $sideBvTotal];
    }

    /** floor неотрицательного bc-числа в int. */
    private function bcFloorToInt(string $value): int
    {
        $intPart = explode('.', $value, 2)[0];

        return (int) $intPart;
    }
}
