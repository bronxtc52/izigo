<?php

namespace Modules\Calculator\V2\Services\Volume;

use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\PaidOrderV2Step;
use Modules\Calculator\V2\Contracts\PvLotService;

/**
 * T03: шаг пайплайна пост-оплаты — volume-слой (снапшот BV/PV → PV-лоты →
 * branch-stats). Сам гейтится флагом mh_v2_volumes (deny-by-default): выключен —
 * ни одной V2-записи, V1-путь не изменён. Идемпотентен по заказу (unique-ключи
 * снапшотов/лотов). Выполняется в транзакции markPaid под advisory-lock активаций.
 */
class VolumeCaptureStep implements PaidOrderV2Step
{
    public const FLAG = 'mh_v2_volumes';

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly PvLotService $volumes,
    ) {
    }

    public function handle(int $orderId): void
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return;
        }

        $this->volumes->recordPaidOrder($orderId);
    }
}
