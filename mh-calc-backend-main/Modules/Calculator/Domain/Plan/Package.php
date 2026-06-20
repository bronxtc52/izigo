<?php

namespace Modules\Calculator\Domain\Plan;

use Modules\Calculator\Domain\ValueObject\Pv;

/** Пакет (контракт): id, порядок, объём PV. Цена в USD — на уровне продаж, не в ядре. */
final class Package
{
    public function __construct(
        public readonly int $id,
        public readonly int $sort,
        public readonly string $name,
        public readonly Pv $pv,
    ) {
    }
}
