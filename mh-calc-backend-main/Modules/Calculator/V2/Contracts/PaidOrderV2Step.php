<?php

namespace Modules\Calculator\V2\Contracts;

/**
 * V2: один шаг пост-оплатной обработки заказа в PaidOrderV2Pipeline.
 * Шаг обязан быть идемпотентным по заказу (повтор runFor = no-op шага).
 * Пример: ReferralBonusStep (T07). См. amendments nice-to-have #4.
 */
interface PaidOrderV2Step
{
    /** Обработать оплаченный заказ. Вызывается внутри транзакции оплаты. */
    public function handle(int $orderId): void;
}
