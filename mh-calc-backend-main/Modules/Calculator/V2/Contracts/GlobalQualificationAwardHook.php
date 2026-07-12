<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: контракт-хук наград VP (владелец — T10, потребитель — T09). T09 при фиксации
 * МЕСЯЧНОЙ квалификации глобального бонуса участника вызывает этот метод ровно один
 * раз на (member, месяц); T10 сам решает, порождает ли квалификация транш VP
 * (только если достигнутый ранг участника == VICE_PRESIDENT: первая distinct
 * месячная квалификация → этап 2, вторая → этап 3, повтор того же месяца → no-op,
 * третья и далее → ничего; DEC-042 спека A).
 *
 * Сигнатура зафиксирована на Гейте A: менять — только правкой amendments, иначе
 * интеграция T09↔T10 разъедется. Идемпотентность гарантируется на стороне T10
 * (unique(member, VICE_PRESIDENT, stage) + сверка месяца в trigger_ref).
 */
interface GlobalQualificationAwardHook
{
    /**
     * @param int    $memberId участник, закрывший месячную квалификацию глобального бонуса
     * @param string $monthKey месяц квалификации 'YYYY-MM' (immutable-снапшот T09)
     */
    public function onGlobalQualificationCompleted(int $memberId, string $monthKey): void;
}
