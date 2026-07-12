<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * T09: месячный PV реферального дерева партнёра — Σ PV-снапшотов (T03) PAID-заказов
 * месяца по всему поддереву спонсорства (Member.sponsor_id, рекурсивный CTE), плюс
 * личный PV партнёра, если include_personal_pv (дефолт Гейта A = true).
 *
 * PV — decimal(18,6); суммируется в целочисленных МИКРО-PV (PV × 1e6) через точный
 * numeric-SUM Postgres, чтобы доли считались целочисленной математикой без float
 * (деньги/пороги — деньги!). Реферальное дерево = цепочки sponsor_id (бинарный
 * ltree-path для глобального бонуса НЕ используется).
 *
 * Если в ServicesV2/Tree появится общий обход (T05/T07/T08) — заменить реализацию,
 * сигнатуру сохранить.
 */
class ReferralTreePvMonthlyService
{
    private const MICRO = 1_000_000; // PV хранится с 6 знаками после запятой

    /**
     * Целочисленный месячный PV реф-дерева в МИКРО-PV (PV × 1e6).
     *
     * @param DateTimeInterface $start начало месяца (включительно, UTC)
     * @param DateTimeInterface $end   конец месяца (исключительно, UTC)
     */
    public function treePvMicro(int $memberId, DateTimeInterface $start, DateTimeInterface $end, bool $includePersonal): int
    {
        // Точный numeric-SUM Postgres по поддереву спонсорства + (опц.) личный узел.
        // ::text — чтобы драйвер не терял точность через float.
        $personalClause = $includePersonal ? 'OR o.member_id = ?' : '';
        $bindings = [$memberId, $start, $end, $memberId];
        if (! $includePersonal) {
            $bindings = [$memberId, $start, $end];
        }

        $sql = <<<SQL
            WITH RECURSIVE subtree AS (
                SELECT id FROM members WHERE sponsor_id = ?
                UNION
                SELECT m.id FROM members m JOIN subtree s ON m.sponsor_id = s.id
            )
            SELECT COALESCE(SUM(o.pv), 0)::text AS s
            FROM v2_order_volume_snapshots o
            WHERE o.paid_at >= ? AND o.paid_at < ?
              AND (o.member_id IN (SELECT id FROM subtree) {$personalClause})
        SQL;

        $row = DB::selectOne($sql, $bindings);

        return $this->decimalToMicro($row->s ?? '0');
    }

    /** Форматировать МИКРО-PV обратно в строку decimal(.,6) для хранения в снапшоте. */
    public function microToDecimalString(int $micro): string
    {
        $sign = $micro < 0 ? '-' : '';
        $micro = abs($micro);
        $int = intdiv($micro, self::MICRO);
        $frac = $micro % self::MICRO;

        return sprintf('%s%d.%06d', $sign, $int, $frac);
    }

    /** Точный разбор numeric-строки ("123.456789"/"0"/"12") в целочисленные микро-PV. */
    private function decimalToMicro(string $value): int
    {
        $value = trim($value);
        $neg = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        [$intPart, $fracPart] = array_pad(explode('.', $value, 2), 2, '');
        $fracPart = substr(str_pad($fracPart, 6, '0'), 0, 6); // ровно 6 знаков (усечение вниз)

        $micro = (int) $intPart * self::MICRO + (int) $fracPart;

        return $neg ? -$micro : $micro;
    }
}
