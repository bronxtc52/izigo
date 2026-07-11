<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: один вариант квалификации статуса (SILVER_MANAGER и выше).
 * anchor/support-ранги заданы на уровне статуса ({@see StatusRule}); здесь — счётчики
 * слотов, компаратор (дефолт Гейта A: «и выше» для всех вариантов, конфиг per-variant)
 * и требование различных корневых реферальных ветвей (BR-TREE-001, DEC-023).
 */
final class QualificationVariantRule
{
    public const COMPARATOR_EXACT = 'exact';
    public const COMPARATOR_AT_LEAST = 'at_least';

    public function __construct(
        public readonly string $code,
        public readonly int $anchorCount,
        public readonly int $supportCount,
        public readonly string $comparator,
        public readonly bool $distinctRootBranches,
    ) {
    }
}
