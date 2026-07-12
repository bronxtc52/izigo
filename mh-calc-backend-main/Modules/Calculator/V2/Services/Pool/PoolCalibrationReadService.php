<?php

namespace Modules\Calculator\V2\Services\Pool;

use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Models\PoolCalibration;

/**
 * T11: реализация контракта PoolCalibrationReader (перебивает Null-дефолт T04).
 * Отдаёт factor_bps ЗАКОММИЧЕННОЙ калибровки месяца — читают T08 (лидерский, от
 * post-calibration базы) и T04 NsToOsTransfer (перевод НС→ОС). null = месяц ещё не
 * откалиброван (fail-closed: перевод НС→ОС не выполняется до коммита калибровки, MF-4).
 */
class PoolCalibrationReadService implements PoolCalibrationReader
{
    public function factorBpsFor(string $month): ?int
    {
        $row = PoolCalibration::query()
            ->where('month', $month)
            ->where('status', PoolCalibration::STATUS_COMMITTED)
            ->first(['factor_bps']);

        return $row === null ? null : (int) $row->factor_bps;
    }
}
