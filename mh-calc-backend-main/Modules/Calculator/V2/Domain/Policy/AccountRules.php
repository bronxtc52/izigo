<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: правила счетов ОС/НС/БС (T02/T04):
 *  - ОС: выводимый, оплата заказа с ОС <= 70% (7000 bp), лоты 1 год, истёкшее → БС;
 *  - НС: технический накопительный, переводы в ОС 1-го и 16-го (семантика перевода —
 *    amendments MF-4: после месячной калибровки, job 1-го числа за оба полумесяца);
 *  - БС: невыводимый, покупки разрешены, лоты 1 год, истёкшее сгорает;
 *  - списание лотов EARLIEST_EXPIRY_FIRST (DEC-015);
 *  - internalFundingFullBv (amendments nice-to-have #6): внутренне-финансируемые заказы
 *    (ОС<=70% + БС) дают полноценный BV.
 */
final class AccountRules
{
    /**
     * @param int[] $nsTransferDays
     */
    public function __construct(
        public readonly bool $osWithdrawable,
        public readonly int $osMaxOrderPaymentShareBp,
        public readonly int $osLotLifetimeDays,
        public readonly string $osOnExpiry,
        public readonly array $nsTransferDays,
        public readonly string $nsTransferTo,
        public readonly bool $bsWithdrawable,
        public readonly bool $bsPurchasable,
        public readonly int $bsLotLifetimeDays,
        public readonly string $bsOnExpiry,
        public readonly string $lotConsumption,
        public readonly bool $internalFundingFullBv,
    ) {
    }
}
