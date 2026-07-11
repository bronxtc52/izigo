<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: перевод НС→ОС ПОСЛЕ месячной калибровки (сервис-операция — T02; команда и
 * расписание — ТОЛЬКО T04: `calc-v2:ns-os-transfer`, ежедневно, гейт «месяц закрыт
 * и откалиброван»).
 *
 * Контракты: amendments MF-4 (принят вариант A — структурная премия остаётся на НС
 * до закрытия МЕСЯЦА и коммита калибровки; провизорных переводов 16-го и clawback
 * калибровки НЕТ) + MF-6 (один владелец переводов; команды `mh2:ns-transfer` НЕ
 * существует). factor_bps читается из закоммиченной строки v2_pool_calibrations
 * (владелец формулы — T11 PoolFactorService, MF-1/2).
 */
interface NsToOsTransfer
{
    /**
     * Перевести НС→ОС за оба полумесяца откалиброванного месяца, уже умноженное
     * на factor_bps: paid_cents = intdiv(raw_cents * $factorBps, 10000).
     * Идемпотентно по месяцу (повтор = no-op).
     *
     * Скоуп СТРОГО месячный (ревью W1 MF-3): переводятся только НС-начисления,
     * атрибутированные $month (meta.ns_month кредит-проводок НС — штампует
     * LedgerV2::credit()), а НЕ весь плоский баланс НС. Начисления других месяцев
     * остаются на НС до калибровки своего месяца.
     *
     * @param string $month     месяц в формате 'YYYY-MM' (UTC-границы периодов, T04)
     * @param int    $factorBps закоммиченный фактор калибровки, 0..10000 basis points
     */
    public function executeForCalibratedMonth(string $month, int $factorBps): void;
}
