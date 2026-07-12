<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PoolCalibrationReader;

/** Фейковый reader калибровки: карта 'YYYY-MM' → factor_bps (null = не откалиброван). */
class FakePoolCalibrationReader implements PoolCalibrationReader
{
    public function __construct(private readonly array $map)
    {
    }

    public function factorBpsFor(string $month): ?int
    {
        return $this->map[$month] ?? null;
    }
}
