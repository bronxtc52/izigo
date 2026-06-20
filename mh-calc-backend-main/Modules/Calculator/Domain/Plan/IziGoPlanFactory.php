<?php

namespace Modules\Calculator\Domain\Plan;

use Modules\Calculator\Domain\ValueObject\Money;
use Modules\Calculator\Domain\ValueObject\Percent;
use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Дефолтный маркетинг-план IziGo (PV-модель). Значения портированы из калькулятора,
 * база BV→PV. USD-суммы ранговых бонусов — бизнес-параметр (по умолчанию 0, TBD).
 * В будущем источник плана — БД/конфиг; фабрика инкапсулирует дефолты.
 */
final class IziGoPlanFactory
{
    /** @param array<int,Money> $rankBonuses rankId => Money (опц., иначе 0) */
    public static function create(array $rankBonuses = []): Plan
    {
        $packages = [
            new Package(1, 1, 'Bronze', Pv::fromUnits(90)),
            new Package(2, 2, 'Silver', Pv::fromUnits(180)),
            new Package(3, 3, 'Gold',   Pv::fromUnits(540)),
        ];

        $b = static fn (int $id) => $rankBonuses[$id] ?? Money::zero();
        $ranks = [
            // id, sort, alias, smallBranchPV, personalCount, inRankCount, inRankId, bonus
            new Rank(1, 1, 'consultant',     Pv::fromUnits(0),    1, 0, 0, $b(1)),
            new Rank(2, 2, 'manager',        Pv::fromUnits(1000), 4, 0, 0, $b(2)),
            new Rank(3, 3, 'manager_bronze', Pv::fromUnits(3000), 8, 0, 0, $b(3)),
            new Rank(4, 4, 'manager_silver', Pv::fromUnits(8000), 0, 3, 2, $b(4)),
        ];

        $binaryPercentByRank = [
            1 => Percent::of(5),
            2 => Percent::of(5),
            3 => Percent::of(5),
            4 => Percent::of(5),
        ];

        // [packageSort][level] => percent
        $referralPercent = [
            1 => [1 => Percent::of(10), 2 => Percent::of(0)],
            2 => [1 => Percent::of(10), 2 => Percent::of(5)],
            3 => [1 => Percent::of(10), 2 => Percent::of(8)],
        ];

        // [level][packageId][rankId] => percent
        $leaderPercent = [
            1 => [
                1 => [2 => Percent::of(10), 3 => Percent::of(10), 4 => Percent::of(10)],
                2 => [2 => Percent::of(15), 3 => Percent::of(15), 4 => Percent::of(15)],
                3 => [2 => Percent::of(20), 3 => Percent::of(20), 4 => Percent::of(20)],
            ],
            2 => [
                3 => [4 => Percent::of(10)],
            ],
        ];

        return new Plan($packages, $ranks, $binaryPercentByRank, $referralPercent, $leaderPercent, maxRankDiff: 2, referralDepth: 2);
    }
}
