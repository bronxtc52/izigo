<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: read-API статусов/тиров для соседних задач (владелец — T05, имплементация —
 * StatusReadService). КОНТРАКТ ВОЛНЫ: T06/T08/T09 читают достигнутый ранг, а T07 —
 * тир, ТОЛЬКО через as-of методы этого интерфейса (v2_rank_history/v2_tier_history);
 * прямое чтение v2_partner_states.current_rank_code ломает исторические пересчёты
 * half-month (риск-карта Гейта A).
 *
 * ИНВАРИАНТ as-of (architect, W2 review): достигнутые ранги/тиры append-only и
 * immutable (unique(member_id, rank_code)/(member_id, tier) + insertOrIgnore), а
 * achieved_at/effective_at монотонны с ordinal (высший достигнут не раньше низшего);
 * коды не ремапятся между версиями политики => as-of version-agnostic. Реализация и
 * обоснование enforcement — в phpdoc StatusReadService::rankAsOf / TierService::tierAsOf.
 */
interface StatusReader
{
    /** Код высшего ранга, достигнутого к моменту $at (null = ранга ещё нет). */
    public function rankAsOf(int $memberId, \DateTimeInterface $at): ?string;

    /** Тир контракта, действовавший на момент $at (null = ниже START). */
    public function tierAsOf(int $memberId, \DateTimeInterface $at): ?string;
}
