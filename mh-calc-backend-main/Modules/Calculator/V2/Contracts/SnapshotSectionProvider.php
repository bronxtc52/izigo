<?php

namespace Modules\Calculator\V2\Contracts;

use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * V2 T04 (опциональный, additive): шаг закрытия, которому нужно ЗАМОРОЗИТЬ свои
 * входы в снапшоте. Снапшот создаётся ДО исполнения шагов (immutable-доказательство
 * входов) — поэтому секции собираются отдельным pre-freeze хуком: оркестратор
 * вызывает sections() у всех шагов периода ПЕРЕД SnapshotService::freeze(), затем
 * исполняет execute(). Реализуют close-steps T06/T09/T11 по потребности
 * (манифесты объёмов/пула); обычному шагу без входных секций интерфейс не нужен.
 */
interface SnapshotSectionProvider
{
    /**
     * Секции снапшота: имя секции → детерминированные данные (без time()/рандома).
     * Имена секций не должны конфликтовать с базовыми (period/policy/payments).
     *
     * @return array<string, array>
     */
    public function sections(CalcPeriod $period): array;
}
