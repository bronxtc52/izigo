<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Modules\Calculator\V2\Contracts\PeriodCloseStep;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;

/** Шаг-регистратор порядка исполнения (лог в статике). */
class RecordingStep implements PeriodCloseStep
{
    /** @var string[] лог меток исполнения по порядку */
    public static array $log = [];

    public function __construct(
        private readonly string $label,
        private readonly int $order,
        private readonly string $supportsType = CalcPeriod::TYPE_HALF_MONTH,
    ) {
    }

    public function supports(string $periodType): bool
    {
        return $periodType === $this->supportsType;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function execute(CalcRun $run, CalcPeriod $period): array
    {
        self::$log[] = $this->label;

        return ['label' => $this->label, 'order' => $this->order];
    }
}
