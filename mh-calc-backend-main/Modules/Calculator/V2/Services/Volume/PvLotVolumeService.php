<?php

namespace Modules\Calculator\V2\Services\Volume;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Calculator\Models\Order;
use Modules\Calculator\V2\Contracts\PvLotService;
use Modules\Calculator\V2\Models\PvLot;

/**
 * T03: реализация контракта V2\Contracts\PvLotService — оркестрация volume-слоя.
 *
 * recordPaidOrder: снапшот (DEC-003) → лоты всем бинарным предкам (DEC-055) →
 * recompute branch-stats; всё идемпотентно (unique-ключи), вызывается из
 * PaidOrderV2Pipeline в ТОЙ ЖЕ транзакции markPaid под уже взятым
 * advisory-lock активаций (проверяется ActivationLockGuard).
 *
 * runMatchingForPeriod: детерминированный run_uuid 'period:{code}' — повтор
 * закрытия периода не создаёт новых матчей (идемпотентность по периоду);
 * cutoff — правая граница полуоткрытого окна half-month (UTC, контракт T04).
 */
class PvLotVolumeService implements PvLotService
{
    public function __construct(
        private readonly OrderVolumeSnapshotService $snapshots,
        private readonly PvLotIngestService $ingest,
        private readonly BinaryMatchingService $matching,
        private readonly BranchStatsService $branchStats,
        private readonly ActivationLockGuard $lockGuard,
    ) {
    }

    public function recordPaidOrder(int $orderId): void
    {
        $this->lockGuard->assertLockHeld();

        $order = Order::query()->find($orderId);
        if ($order === null || $order->member_id === null) {
            return; // нет заказа/участника — объёмы складывать некому (лид без промоушена)
        }

        $snapshots = $this->snapshots->captureOnPaid($order);
        $owners = $this->ingest->createLotsForPaidOrder($snapshots);
        $this->branchStats->recomputeMany($owners);
    }

    public function runMatchingForPeriod(string $periodCode): void
    {
        // Ревью W1 MF-7 (amendments #5): периодный матчинг мутирует PV-лоты —
        // оркестратор (закрытие периода T04 / админ-триггер) обязан держать
        // advisory-lock активаций; здесь только проверяем.
        $this->lockGuard->assertLockHeld();

        $cutoff = self::cutoffForPeriod($periodCode);
        $runUuid = 'period:' . $periodCode;

        $ownerIds = PvLot::query()
            ->where('occurred_at', '<', $cutoff)
            ->distinct()
            ->pluck('owner_member_id');

        foreach ($ownerIds as $ownerId) {
            $this->matching->runMatching((int) $ownerId, $cutoff, $periodCode, $runUuid);
        }
    }

    /**
     * Правая граница half-month окна (UTC, полуоткрытый интервал [start, end)):
     * H1 = [1-е 00:00, 16-е 00:00), H2 = [16-е 00:00, 1-е след. месяца 00:00).
     */
    public static function cutoffForPeriod(string $periodCode): CarbonImmutable
    {
        // Месяц строго 01..12: '(\d{2})' пропускал '2026-13', который Carbon
        // нормализует в 2027-01 — период/cutoff не того окна (примечание ревью W1 #1).
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])-(H1|H2)$/', $periodCode, $m)) {
            throw new InvalidArgumentException(
                "Неверный код half-month периода: {$periodCode} (ожидается YYYY-MM-H1|H2)"
            );
        }

        $monthStart = CarbonImmutable::createFromFormat('!Y-m-d', "{$m[1]}-{$m[2]}-01", 'UTC');

        return $m[3] === 'H1'
            ? $monthStart->addDays(15)      // 16-е 00:00 UTC
            : $monthStart->addMonthsNoOverflow(1); // 1-е след. месяца 00:00 UTC
    }
}
