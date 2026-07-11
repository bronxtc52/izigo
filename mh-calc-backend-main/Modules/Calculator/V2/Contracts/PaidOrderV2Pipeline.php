<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: ЕДИНСТВЕННАЯ точка расширения пост-оплаты (владелец — T03; вызов — из
 * PaymentService::applyPaid / OrderService::markPaid за фиче-флагом
 * mh_plan_v2_engine). Никто не правит markPaid напрямую: T07 регистрирует
 * ReferralBonusStep здесь — amendments nice-to-have #4.
 *
 * Advisory-lock ACTIVATION_LOCK берёт внешний оркестратор события оплаты ДО
 * ledger-записей (жёсткая рамка проекта + amendments nice-to-have #5); шаги
 * пайплайна — assertLockHeld().
 */
interface PaidOrderV2Pipeline
{
    /** Зарегистрировать шаг (порядок регистрации = порядок исполнения). */
    public function register(PaidOrderV2Step $step): void;

    /** Прогнать все зарегистрированные шаги для оплаченного заказа. Идемпотентно. */
    public function runFor(int $orderId): void;
}
