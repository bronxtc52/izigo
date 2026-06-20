<?php

namespace Modules\Calculator\Domain\ValueObject;

/**
 * Процент (например 5 = 5%). Применение к minor units с округлением half-up до цента.
 */
final class Percent
{
    private function __construct(public readonly float $value)
    {
    }

    public static function of(float $value): self
    {
        return new self($value);
    }

    public function isZero(): bool
    {
        return $this->value == 0.0;
    }

    /** Применить к PV-сотым/центам, вернуть целые minor units (half-up). */
    public function applyToMinor(int $minorUnits): int
    {
        return (int) round($minorUnits * $this->value / 100);
    }

    /** Процент от Money → Money. */
    public function ofMoney(Money $money): Money
    {
        return Money::fromCents($this->applyToMinor($money->cents));
    }

    /** Процент от Pv → Money (1 PV = $1). */
    public function ofPvAsMoney(Pv $pv): Money
    {
        return Money::fromCents($this->applyToMinor($pv->hundredths));
    }
}
