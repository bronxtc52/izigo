<?php

namespace Modules\Calculator\V2\Services\Wallet;

/**
 * mh-full-plan T02: параметры блока accounts.* политики V2 — fail-safe дефолты в коде
 * (план T02: «дефолты дублирует в коде»). Значения = дефолтный конфиг Гейта A:
 * ОС ≤70% оплаты заказа (7000 bp), лоты ОС/БС — 1 год (365 дней).
 *
 * TODO(T01-merge): читать значения через Contracts\PolicyVersionResolver::forDate(),
 * когда T01 наполнит PolicyV2 аксессорами конфига (accounts.OS.max_order_payment_share_bp,
 * accounts.OS/BS.lot_expiry). Контракт заморожен amendments MF-5; правка — только этот
 * класс-адаптер, потребители (WalletAccountsV2Service / OrderAccountPaymentService)
 * не меняются.
 */
class AccountsPolicyV2
{
    /** Максимум оплаты заказа с ОС, basis points (70%). */
    public function osOrderPaymentMaxShareBp(\DateTimeInterface $at): int
    {
        return 7000;
    }

    /** Срок жизни ОС-лота, дней (BR-ACC-001). */
    public function osLotLifetimeDays(\DateTimeInterface $at): int
    {
        return 365;
    }

    /** Срок жизни БС-лота, дней (BR-ACC-004: 1 год с даты переноса). */
    public function bsLotLifetimeDays(\DateTimeInterface $at): int
    {
        return 365;
    }
}
