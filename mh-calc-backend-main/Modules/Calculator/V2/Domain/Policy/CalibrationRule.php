<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: 60%-калибровка выплат (T11, DEC-014 + amendments MF-1/MF-2):
 * pool_cap = base_bv * rate_bp / 10000, только пропорциональный scale-down (никогда вверх).
 * Числитель месяца (include): структурная премия после индивидуальных капов + реферальная
 * + месячное накопление глобального; лидерский НЕ входит (следствие DEC-029, иначе цикл),
 * квал-награды исключены решением владельца Гейта A (owner-approved исключение из DEC-014).
 */
final class CalibrationRule
{
    /**
     * @param array<string, bool> $include ключи: structure_after_caps, referral,
     *                                     global_pool_monthly, leadership, awards
     */
    public function __construct(
        public readonly int $rateBp,
        public readonly string $mode,
        public readonly string $base,
        public readonly array $include,
    ) {
    }
}
