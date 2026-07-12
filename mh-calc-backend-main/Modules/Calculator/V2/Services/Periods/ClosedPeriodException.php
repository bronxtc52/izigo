<?php

namespace Modules\Calculator\V2\Services\Periods;

/**
 * V2 T04: попытка провести изменение в НЕоткрытый период. Единый guard денег:
 * все V2-постинги обязаны звать PeriodService::assertOpen() перед проводками.
 * Корректирующий путь T12 идёт с явным correction-флагом и НЕ ослабляет этот guard.
 */
class ClosedPeriodException extends \DomainException
{
}
