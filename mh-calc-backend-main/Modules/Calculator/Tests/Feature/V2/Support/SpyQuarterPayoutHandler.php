<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\QuarterGlobalPayoutHandler;
use Modules\Calculator\V2\Domain\CalcPeriod;

/** Шпион квартальной выплаты глобального пула. */
class SpyQuarterPayoutHandler implements QuarterGlobalPayoutHandler
{
    /** @var array<int, array{quarter:string, month_ids:array, window:string}> */
    public array $calls = [];

    public function payQuarter(CalcPeriod $quarter, array $monthPeriodIds, string $windowKey): array
    {
        $this->calls[] = ['quarter' => $quarter->code, 'month_ids' => $monthPeriodIds, 'window' => $windowKey];

        return ['handler' => 'spy', 'months' => count($monthPeriodIds)];
    }
}
