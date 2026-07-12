<?php

namespace Modules\Calculator\V2\Domain\Bonus;

use Modules\Calculator\V2\Domain\Policy\LeadershipRule;

/**
 * T08 — ЧИСТЫЙ расчёт лидерского бонуса CAL-LED-001 (без Eloquent, детерминирован,
 * целочисленная математика в USD-центах). Псевдокод спеки 03_Calculation_Engine
 * §11: walk вверх по sponsor-цепочке от источника, depth++ ВСЕГДА (без компрессии).
 *
 * Для каждого получателя (ancestor на глубине depth 1..eliteMaxDepth):
 *  1. rank < MANAGER (eligibility) → BELOW_MANAGER, пропуск, depth++ (без компрессии);
 *  2. rank-gap (DEC-030, «блок без передачи»): если среди узлов пути НИЖЕ получателя
 *     (source + пройденные ancestors, включая source) есть узел с
 *     ordinal >= receiver_ordinal + rankGapBlockOrdinalDiff → RANK_GAP_BLOCK
 *     (blocking_member = виновник с максимальным ordinal), выплата НЕ передаётся;
 *  3. ставка по тиру получателя × глубине:
 *     START/BUSINESS — только L1 (10%/15%), глубже → DEPTH_NOT_ALLOWED;
 *     ELITE — глубина по рангу (Manager 1 … Sapphire/Diamond/VP 7), ставки
 *     20/10/5/3/1/1/1%, за пределом ранга → DEPTH_NOT_ALLOWED; нулевая ставка → RATE_ZERO;
 *  4. amount = intdiv(base_cents × rate_bp, 10000) (floor, integer-центы, DEC-002).
 *
 * База (DEC-029) передаётся снаружи как $baseCents = net структурной премии источника
 * ПОСЛЕ капов и 60%-калибровки — калькулятор её не выводит.
 */
class LeadershipCalculator
{
    public const TIER_START = 'START';
    public const TIER_BUSINESS = 'BUSINESS';
    public const TIER_ELITE = 'ELITE';

    /**
     * @param LeadershipChainNode   $source источник структурной премии (низ цепочки)
     * @param int                   $baseCents net-база (DEC-029), >= 0
     * @param LeadershipChainNode[] $chain    ancestors от source.sponsor вверх (по порядку)
     *
     * @return LeadershipLine[] по одной строке на каждого пройденного получателя (accrued|excluded)
     */
    public function compute(
        LeadershipChainNode $source,
        int $baseCents,
        array $chain,
        LeadershipRule $rule,
    ): array {
        $managerOrdinal = $rule->eligibilityStatusMin->ordinal();
        $gap = $rule->rankGapBlockOrdinalDiff;
        $maxWalk = $rule->eliteMaxDepth;

        // «Нижние» узлы для rank-gap: стартуем с источника (он всегда ниже любого получателя,
        // путь source..receiver ВКЛЮЧАЕТ source). Узлы без ранга в максимум не входят.
        $belowMaxOrdinal = $source->rankOrdinal;
        $belowMaxMember = $source->rankOrdinal === null ? null : $source->memberId;

        $lines = [];
        $depth = 1;
        foreach ($chain as $receiver) {
            if ($depth > $maxWalk) {
                break;
            }

            $rankOrd = $receiver->rankOrdinal;

            // (1) Ниже MANAGER — лидерский не получает, но depth инкрементится (без компрессии).
            if ($rankOrd === null || $rankOrd < $managerOrdinal) {
                $lines[] = LeadershipLine::excluded($receiver, $depth, LeadershipLine::REASON_BELOW_MANAGER, $baseCents);
                [$belowMaxOrdinal, $belowMaxMember] = $this->pushBelow($belowMaxOrdinal, $belowMaxMember, $receiver);
                $depth++;
                continue;
            }

            // (2) rank-gap блок ветви (DEC-030 «без передачи»).
            if ($belowMaxOrdinal !== null && $belowMaxOrdinal >= $rankOrd + $gap) {
                $lines[] = LeadershipLine::excluded(
                    $receiver,
                    $depth,
                    LeadershipLine::REASON_RANK_GAP_BLOCK,
                    $baseCents,
                    $belowMaxMember,
                );
                [$belowMaxOrdinal, $belowMaxMember] = $this->pushBelow($belowMaxOrdinal, $belowMaxMember, $receiver);
                $depth++;
                continue;
            }

            // (3) ставка по тиру × глубине.
            [$rateBp, $reason] = $this->resolveRate($receiver, $depth, $rule);
            if ($rateBp > 0) {
                $amount = intdiv($baseCents * $rateBp, 10000); // floor, integer-центы (DEC-002)
                $lines[] = LeadershipLine::accrued($receiver, $depth, $rateBp, $baseCents, $amount);
            } else {
                $lines[] = LeadershipLine::excluded($receiver, $depth, $reason, $baseCents);
            }

            [$belowMaxOrdinal, $belowMaxMember] = $this->pushBelow($belowMaxOrdinal, $belowMaxMember, $receiver);
            $depth++;
        }

        return $lines;
    }

    /**
     * Ставка (bp) и — если 0 — причина исключения (DEPTH_NOT_ALLOWED|RATE_ZERO).
     *
     * @return array{0:int,1:?string}
     */
    private function resolveRate(LeadershipChainNode $receiver, int $depth, LeadershipRule $rule): array
    {
        $tier = $receiver->tier;

        if ($tier === self::TIER_START || $tier === self::TIER_BUSINESS) {
            $rates = $tier === self::TIER_START ? $rule->startRatesBp : $rule->businessRatesBp;
            $allowedDepth = count($rates); // START/BUSINESS — только L1
            if ($depth > $allowedDepth) {
                return [0, LeadershipLine::REASON_DEPTH_NOT_ALLOWED];
            }
            $rate = (int) ($rates[$depth - 1] ?? 0);

            return $rate > 0 ? [$rate, null] : [0, LeadershipLine::REASON_RATE_ZERO];
        }

        if ($tier === self::TIER_ELITE) {
            // Глубина ELITE = min(глобальный предел, глубина ранга получателя).
            $allowedDepth = min($rule->eliteMaxDepth, $receiver->eliteMaxDepth);
            if ($depth > $allowedDepth) {
                return [0, LeadershipLine::REASON_DEPTH_NOT_ALLOWED];
            }
            $rate = (int) ($rule->eliteRatesBp[$depth - 1] ?? 0);

            return $rate > 0 ? [$rate, null] : [0, LeadershipLine::REASON_RATE_ZERO];
        }

        // Тир не резолвится (ниже START) — при ранге >= MANAGER это краевой случай.
        return [0, LeadershipLine::REASON_RATE_ZERO];
    }

    /**
     * Добавить узел в «нижние» для rank-gap вышестоящих (max ordinal + его владелец).
     * Узел без ранга максимум не двигает.
     *
     * @return array{0:?int,1:?int}
     */
    private function pushBelow(?int $belowMaxOrdinal, ?int $belowMaxMember, LeadershipChainNode $node): array
    {
        if ($node->rankOrdinal !== null
            && ($belowMaxOrdinal === null || $node->rankOrdinal > $belowMaxOrdinal)) {
            return [$node->rankOrdinal, $node->memberId];
        }

        return [$belowMaxOrdinal, $belowMaxMember];
    }
}
