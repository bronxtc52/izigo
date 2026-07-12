<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: PV-лоты бинара (владелец — T03). BV (integer USD-центы) и PV (decimal(18,6),
 * amendments nice-to-have #3) — раздельные величины со снапшотом на заказ (DEC-003).
 * Таблица — v2_pv_lots, СРАЗУ с колонкой reversal_of_lot_id (закладывает T03,
 * использует T12) — amendments MF-8.
 *
 * matched_bv_cents из матчинга — ЕДИНСТВЕННЫЙ денежный вход T06 (T06 не
 * пере-выводит BV из PV) — amendments nice-to-have #3.
 */
interface PvLotService
{
    /**
     * Снять BV/PV-снапшот оплаченного заказа (из OrderItem на моменте markPaid,
     * DEC-055) и разложить PV-лоты по сторонам L/R всех binary-ancestors.
     * Идемпотентно по заказу (повтор = no-op). Вызывается из PaidOrderV2Pipeline.
     */
    public function recordPaidOrder(int $orderId): void;

    /**
     * Прогнать matching min(free L, free R) по всем участникам за период;
     * carryover без сгорания. Идемпотентно по периоду.
     *
     * @param string $periodCode код half-month периода из v2_calc_periods (T04),
     *                           формат 'YYYY-MM-H1' | 'YYYY-MM-H2'
     */
    public function runMatchingForPeriod(string $periodCode): void;
}
