<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: субсчета ОС/НС/БС поверх существующего double-entry ledger (владелец — T02).
 * Деньги — ТОЛЬКО integer USD-центы. Кредит-лоты со сроком годности (FIFO по
 * EARLIEST_EXPIRY_FIRST, DEC-015); таблица лотов — v2_wallet_lots (amendments MF-8).
 *
 * Контракты: amendments MF-9 — $expiresAt НУЛЛАБЕЛЬНЫЙ: award-лоты БС кредитуются
 * с null и НЕ сгорают; expireLots (реализация T02) пропускает expires_at IS NULL.
 */
interface LedgerV2
{
    /** Основной счёт: выводимый, оплата заказов ≤70% стоимости, лоты 1 год → в БС. */
    public const SUBACCOUNT_OS = 'os';

    /** Накопительный счёт: структурная премия до месячной калибровки (MF-4), перевод в ОС job'ом T04. */
    public const SUBACCOUNT_NS = 'ns';

    /** Бонусный счёт: только покупки; лоты 1 год → forfeit; award-лоты без сгорания (MF-9). */
    public const SUBACCOUNT_BS = 'bs';

    /**
     * Кредит субсчёта участника новым лотом.
     *
     * @param string                  $subaccount     одна из констант SUBACCOUNT_*
     * @param int                     $amountCents    integer USD-центы, > 0
     * @param string                  $idempotencyKey уникальный ключ проводки (повтор = no-op,
     *                                                паттерн LedgerService::alreadyPosted)
     * @param \DateTimeInterface|null $expiresAt      срок сгорания лота; null = лот НЕ сгорает
     *                                                (award-лоты T10, amendments MF-9)
     */
    public function credit(
        int $memberId,
        string $subaccount,
        int $amountCents,
        string $idempotencyKey,
        ?\DateTimeInterface $expiresAt = null,
    ): void;

    /**
     * Дебет субсчёта участника (списание с лотов FIFO по EARLIEST_EXPIRY_FIRST, DEC-015).
     *
     * @param string $subaccount     одна из констант SUBACCOUNT_*
     * @param int    $amountCents    integer USD-центы, > 0
     * @param string $idempotencyKey уникальный ключ проводки (повтор = no-op)
     */
    public function debit(
        int $memberId,
        string $subaccount,
        int $amountCents,
        string $idempotencyKey,
    ): void;
}
