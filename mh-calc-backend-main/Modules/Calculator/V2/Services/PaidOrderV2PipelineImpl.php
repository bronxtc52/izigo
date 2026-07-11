<?php

namespace Modules\Calculator\V2\Services;

use Modules\Calculator\V2\Contracts\PaidOrderV2Pipeline;
use Modules\Calculator\V2\Contracts\PaidOrderV2Step;

/**
 * T03: единственная точка расширения пост-оплаты (amendments nice-to-have #4).
 * Шаги регистрируются в DI-провайдере (порядок регистрации = порядок исполнения);
 * T05/T07 добавляют СВОИ шаги здесь, markPaid больше никто не правит.
 * Каждый шаг сам гейтится своим фиче-флагом и обязан быть идемпотентным по заказу.
 * Синглтон (регистрация шагов — состояние процесса).
 */
class PaidOrderV2PipelineImpl implements PaidOrderV2Pipeline
{
    /** @var PaidOrderV2Step[] */
    private array $steps = [];

    public function register(PaidOrderV2Step $step): void
    {
        $this->steps[] = $step;
    }

    public function runFor(int $orderId): void
    {
        foreach ($this->steps as $step) {
            $step->handle($orderId);
        }
    }
}
