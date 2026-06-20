<?php

namespace Modules\Calculator\Domain\Result;

use Modules\Calculator\Domain\ValueObject\Money;

/** Аккумулятор результатов расчёта: список начислений + суммы по получателям/типам. */
final class CalculationResult
{
    /** @var BonusLine[] */
    private array $lines = [];
    /** @var array<int,array<int,int>> recipientId => [rankId achieved...] */
    public array $rankAchievements = [];

    public function addBonus(BonusLine $line): void
    {
        if ($line->amount->isPositive()) {
            $this->lines[] = $line;
        }
    }

    public function addRankAchievement(int $memberId, int $rankId): void
    {
        $this->rankAchievements[$memberId][] = $rankId;
    }

    /** @return BonusLine[] */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalForMember(int $memberId): Money
    {
        $sum = Money::zero();
        foreach ($this->lines as $l) {
            if ($l->recipientId === $memberId) {
                $sum = $sum->add($l->amount);
            }
        }
        return $sum;
    }

    public function totalByType(string $type): Money
    {
        $sum = Money::zero();
        foreach ($this->lines as $l) {
            if ($l->type === $type) {
                $sum = $sum->add($l->amount);
            }
        }
        return $sum;
    }

    public function grandTotal(): Money
    {
        $sum = Money::zero();
        foreach ($this->lines as $l) {
            $sum = $sum->add($l->amount);
        }
        return $sum;
    }
}
