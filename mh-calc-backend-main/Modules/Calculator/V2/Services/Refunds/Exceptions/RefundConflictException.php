<?php

namespace Modules\Calculator\V2\Services\Refunds\Exceptions;

/**
 * T12: конфликт состояния корректировки закрытого периода (HTTP 409): approve/post
 * над уже проведённой/отклонённой/непринятой корректировкой.
 */
class RefundConflictException extends \RuntimeException
{
}
