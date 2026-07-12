<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: необратимое аннулирование PV, накопленных участником-CLIENT за grace-период
 * (BR-REG-004 / CAL-GRACE-001: annul_all(GRACE_HELD)). Объявлен T05 (план Гейта A);
 * имплементация работает по лотам v2_pv_lots, ГДЕ УЧАСТНИК — ВЛАДЕЛЕЦ
 * (owner_member_id): обнуляется его накопленный branch-PV, а не лоты его покупок
 * у аплайна.
 *
 * Дисциплина локов (контракт-чек W2+ №3): advisory-lock ACTIVATION_LOCK берёт
 * оркестратор (grace-скан/оплата), имплементация — assertLockHeld().
 * Идемпотентность: повторный вызов с тем же ключом = no-op (0 аннулировано).
 */
interface PvLotAnnulmentInterface
{
    /**
     * @param \DateTimeInterface $until аннулировать лоты с occurred_at <= $until
     * @return int число аннулированных лотов
     */
    public function annulBeneficiaryLots(
        int $memberId,
        \DateTimeInterface $until,
        string $reason,
        string $idempotencyKey,
    ): int;
}
