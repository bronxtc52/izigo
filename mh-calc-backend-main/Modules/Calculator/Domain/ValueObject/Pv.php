<?php

namespace Modules\Calculator\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Point Value — объём в сотых (целое), без float. 90 PV = 9000 hundredths.
 * Биллинг считаем в целых minor units для точности.
 */
final class Pv
{
    private function __construct(public readonly int $hundredths)
    {
    }

    public static function fromHundredths(int $hundredths): self
    {
        return new self($hundredths);
    }

    /** Из целых единиц PV (90 PV -> 9000). */
    public static function fromUnits(int|float $units): self
    {
        return new self((int) round($units * 100));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(Pv $other): self
    {
        return new self($this->hundredths + $other->hundredths);
    }

    public function subtract(Pv $other): self
    {
        return new self($this->hundredths - $other->hundredths);
    }

    public function isZero(): bool
    {
        return $this->hundredths === 0;
    }

    public function greaterThanOrEqual(Pv $other): bool
    {
        return $this->hundredths >= $other->hundredths;
    }

    public static function min(Pv ...$values): self
    {
        if ($values === []) {
            throw new InvalidArgumentException('Pv::min requires at least one value');
        }
        $minor = min(array_map(static fn (Pv $v) => $v->hundredths, $values));
        return new self($minor);
    }

    public function units(): float
    {
        return $this->hundredths / 100;
    }
}
