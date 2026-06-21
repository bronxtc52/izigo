<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;

/**
 * Абстракция платёжного шлюза приёма (Фаза 4). Боевой драйвер — Wallet Pay (USDT/TON),
 * тестовый — Fake. Новый провайдер = новый драйвер, ядро/сервис не трогаем.
 */
interface PaymentGateway
{
    /**
     * Создать инвойс на оплату. $externalRef — наш идентификатор ("pay:{paymentId}"),
     * шлюз возвращает его в webhook. $purpose — order|topup (для описания платежа).
     */
    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult;

    /**
     * Проверить подпись webhook и вернуть нормализованное событие.
     * Возвращает null, если подпись невалидна (хендлер ответит 400).
     */
    public function verifyAndParseWebhook(Request $request): ?WebhookEvent;

    /**
     * Опрос статуса платежа (для non-custodial приёма без webhook, напр. TON Pay):
     * ищет в сети транзакцию на наш адрес с memo=$externalRef и суммой $amountCents.
     * Возвращает: paid | pending | failed | none. Webhook-драйверы возвращают 'none'.
     */
    public function pollStatus(string $externalRef, int $amountCents): string;
}
