<?php

namespace Modules\Calculator\Domain\Plan;

use Modules\Calculator\Domain\ValueObject\Percent;

/**
 * Конфигурация маркетинг-плана: пакеты, ранги и проценты бонусов.
 * Чистые данные, инъектируются в калькуляторы. Значения берутся из конфига/БД.
 */
final class Plan
{
    /** @var array<int,Package> id => Package */
    private array $packagesById = [];
    /** @var array<int,Package> sort => Package */
    private array $packagesBySort = [];
    /** @var Rank[] упорядочены по sort ASC */
    private array $ranksOrdered;
    /** @var array<int,Percent> rankId => Percent, отсортировано по rankId DESC */
    private array $binaryPercentByRankDesc;

    /**
     * @param Package[] $packages
     * @param Rank[] $ranks
     * @param array<int,Percent> $binaryPercentByRank rankId => Percent (малая ветка)
     * @param array<int,array<int,Percent>> $referralPercent [packageSort][level] => Percent
     * @param array<int,array<int,array<int,Percent>>> $leaderPercent [level][packageId][rankId] => Percent
     */
    public function __construct(
        array $packages,
        array $ranks,
        array $binaryPercentByRank,
        private readonly array $referralPercent,
        private readonly array $leaderPercent,
        public readonly int $maxRankDiff = 2,
        public readonly int $referralDepth = 2,
    ) {
        foreach ($packages as $p) {
            $this->packagesById[$p->id] = $p;
            $this->packagesBySort[$p->sort] = $p;
        }
        usort($ranks, static fn (Rank $a, Rank $b) => $a->sort <=> $b->sort);
        $this->ranksOrdered = $ranks;

        krsort($binaryPercentByRank);
        $this->binaryPercentByRankDesc = $binaryPercentByRank;
    }

    public function package(?int $id): ?Package
    {
        return $this->packagesById[$id] ?? null;
    }

    /** @return Rank[] по sort ASC */
    public function ranksOrdered(): array
    {
        return $this->ranksOrdered;
    }

    /** Бинарный процент по рангу получателя: первый rankId<=receiverRank в порядке убывания. */
    public function binaryPercent(int $receiverRankId): Percent
    {
        foreach ($this->binaryPercentByRankDesc as $rankId => $percent) {
            if ($receiverRankId >= $rankId) {
                return $percent;
            }
        }
        return Percent::of(0);
    }

    public function referralPercent(int $packageSort, int $level): Percent
    {
        return $this->referralPercent[$packageSort][$level] ?? Percent::of(0);
    }

    public function leaderPercent(int $level, ?int $packageId, ?int $rankId): Percent
    {
        return $this->leaderPercent[$level][$packageId][$rankId] ?? Percent::of(0);
    }

    public function leaderMaxLevel(): int
    {
        return $this->leaderPercent === [] ? 0 : max(array_keys($this->leaderPercent));
    }
}
