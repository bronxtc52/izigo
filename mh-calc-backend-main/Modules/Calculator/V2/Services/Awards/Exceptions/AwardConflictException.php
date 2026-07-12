<?php

namespace Modules\Calculator\V2\Services\Awards\Exceptions;

/**
 * T10: недопустимый переход статуса награды (напр. выплата on_hold/forfeited,
 * forfeit уже paid_out). Контроллер → 409 Conflict.
 */
class AwardConflictException extends \RuntimeException
{
}
