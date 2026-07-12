<?php

namespace Modules\Calculator\V2\Domain\Bonus;

/**
 * T06: результат расчёта структурной премии окна (чистые integer USD-центы).
 *
 *  - gross            = matched_bv * rate (floor до цента, DEC-002);
 *  - afterCap         = после индивидуальных капов (полумесячный + месячный safety);
 *  - capRemainingBefore = месячный остаток капа ДО этого окна (для explanation/отчёта);
 *  - forfeited        = gross − afterCap — сматченный сверх капа СГОРАЕТ
 *                       (решение владельца Гейт A / amendments), дельта видна.
 */
final class StructureBonusResult
{
    public function __construct(
        public readonly int $grossCents,
        public readonly int $afterCapCents,
        public readonly int $capRemainingBeforeCents,
        public readonly int $forfeitedCents,
    ) {
    }
}
