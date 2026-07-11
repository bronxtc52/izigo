<?php

namespace Modules\Calculator\V2\Services\Periods;

use Modules\Calculator\V2\Contracts\PoolCalibrationReader;

/**
 * V2 T04: Null-дефолт чтения калибровки — «ни один месяц не откалиброван».
 * Гейт MF-4 fail-closed: до merge T11 (PoolFactorService, v2_pool_calibrations)
 * job ns-os-transfer никогда не переводит деньги. T11 перебивает биндинг.
 */
class NullPoolCalibrationReader implements PoolCalibrationReader
{
    public function factorBpsFor(string $month): ?int
    {
        return null;
    }
}
