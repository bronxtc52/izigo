<?php

namespace Modules\Calculator\Domain\ValueObject;

/**
 * Деньги — USD в центах (целое), без float. Модель IziGo: 1 PV = $1,
 * поэтому Money.cents численно равен Pv.hundredths при конвертации 1:1.
 */
final class Money
{
    private function __construct(public readonly int $cents)
    {
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function fromDollars(int|float $dollars): self
    {
        return new self((int) round($dollars * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function dollars(): float
    {
        return $this->cents / 100;
    }
}
