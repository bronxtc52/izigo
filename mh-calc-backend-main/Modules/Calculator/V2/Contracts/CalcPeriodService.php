<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: расчётные периоды (владелец — T04). Half-month (1–15 / 16–конец, UTC), month,
 * quarter; таблица v2_calc_periods (amendments MF-8); запрет изменения закрытых
 * периодов — только корректирующие проводки.
 *
 * ОБЕ операции ИДЕМПОТЕНТНЫ: повторный вызов по уже закрытому периоду = no-op
 * (джобы планировщика перезапускаются, withoutOverlapping не спасает от повтора
 * после рестарта). Advisory-lock ACTIVATION_LOCK берёт внешний оркестратор
 * закрытия периода; внутренние сервисы — assertLockHeld() (amendments
 * nice-to-have #5).
 */
interface CalcPeriodService
{
    /**
     * Закрыть полумесячный период: matching PV (T03), начисление структурной
     * премии на НС (T06, MF-4 — доступность ОС только после месячной калибровки).
     *
     * @param string $periodCode 'YYYY-MM-H1' | 'YYYY-MM-H2'
     */
    public function closeHalfMonth(string $periodCode): void;

    /**
     * Закрыть месяц: оба half-month закрыты → калибровка 60%-пула (T11, коммит
     * factor_bps в v2_pool_calibrations) → месячные накопления (глобальный T09).
     * Перевод НС→ОС НЕ здесь — его выполняет job T04 через NsToOsTransfer (MF-6).
     *
     * @param string $month 'YYYY-MM'
     */
    public function closeMonth(string $month): void;
}
