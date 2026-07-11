<?php

namespace Modules\Calculator\V2\Services\Periods;

use Modules\Calculator\V2\Contracts\QuarterGlobalPayoutHandler;
use Modules\Calculator\V2\Domain\CalcPeriod;

/**
 * V2 T04: Null-дефолт квартальной выплаты глобального пула — до merge T09 квартал
 * закрывается без денежных постингов (выплаты нет, метрика пустая). T09 перебивает
 * биндинг в своём маркер-блоке CalculatorV2ServiceProvider.
 */
class NullQuarterGlobalPayoutHandler implements QuarterGlobalPayoutHandler
{
    public function payQuarter(CalcPeriod $quarter, array $monthPeriodIds, string $windowKey): array
    {
        return ['handler' => 'null', 'paid_cents' => 0];
    }
}
