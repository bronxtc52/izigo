<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;

/** Шаг-бомба: падает, пока $armed — проверка «run failed, период остаётся open». */
class FlakyStep implements PeriodCloseStep
{
    public static bool $armed = true;

    public function supports(string $periodType): bool
    {
        return $periodType === CalcPeriod::TYPE_HALF_MONTH;
    }

    public function order(): int
    {
        return 50;
    }

    public function execute(CalcRun $run, CalcPeriod $period): array
    {
        if (self::$armed) {
            throw new \RuntimeException('boom: шаг закрытия упал');
        }

        return ['recovered' => true];
    }
}
