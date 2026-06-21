<?php

namespace Modules\Calculator\Services\Payment;

/**
 * Результат создания инвойса в платёжном шлюзе.
 */
class InvoiceResult
{
    public function __construct(
        public readonly string $providerId, // id инвойса на стороне шлюза
        public readonly string $payUrl,     // ссылка для оплаты (открывается в Mini App)
    ) {
    }
}
