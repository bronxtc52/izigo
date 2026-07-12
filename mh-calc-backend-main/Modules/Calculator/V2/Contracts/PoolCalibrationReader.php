<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: чтение ЗАКОММИЧЕННОГО фактора 60%-калибровки месяца (владелец формулы и
 * таблицы v2_pool_calibrations — T11 PoolFactorService, amendments MF-1/2; T04
 * даёт Null-дефолт «месяц не откалиброван», T11 перебивает биндинг).
 *
 * Гейт MF-4/MF-6: job `calc-v2:ns-os-transfer` (T04) переводит НС→ОС ТОЛЬКО когда
 * месяц закрыт И откалиброван — до коммита калибровки reader возвращает null и
 * перевод не выполняется (никаких провизорных переводов и clawback'ов).
 */
interface PoolCalibrationReader
{
    /**
     * factor_bps закоммиченной калибровки месяца 'YYYY-MM' (0..10000 basis points)
     * или null, если месяц ещё не откалиброван.
     */
    public function factorBpsFor(string $month): ?int;
}
