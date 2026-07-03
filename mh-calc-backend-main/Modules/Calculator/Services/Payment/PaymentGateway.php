<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;

/**
 * Абстракция платёжного шлюза приёма (Фаза 4). Боевой драйвер — TON Pay (USDT/TON,
 * non-custodial), тестовый — Fake. Новый провайдер = новый драйвер, ядро/сервис не трогаем.
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
     * Возвращает: paid | pending | failed | none | error. Webhook-драйверы возвращают 'none'.
     *
     * 'error' = «опрос НЕ удался» (сеть/таймаут/5xx индексатора) — НЕ бизнес-статус платежа:
     * потребитель не вправе ни финализировать, ни экспирировать платёж по нему, и никогда
     * не пишет 'error' в payments.status. 'pending' = «опросили успешно, перевода нет».
     *
     * $sinceUtime — unix-время (сек) создания платежа: драйвер бьёт им курсор поиска
     * (start_utime у toncenter, минус запас), чтобы старый перевод не выпал из окна опроса
     * при всплеске переводов на merchant-адрес. null = без курсора (совместимость/dev).
     */
    public function pollStatus(string $externalRef, int $amountCents, ?int $sinceUtime = null): string;

    /**
     * Пакетный опрос множества платежей ОДНИМ фетчем списка переводов за тик (устраняет
     * N идентичных HTTP-запросов/мин → rate-limit индексатора). $items — список
     * ['ref' => string, 'amount_cents' => int, 'since_utime' => int|null].
     * Возвращает карту ref => (paid|pending|failed|none|error) с той же семантикой, что pollStatus.
     * Сбой фетча → 'error' по ВСЕМ ref (потребитель их не финализирует и не экспирирует).
     *
     * @param  array<int,array{ref:string,amount_cents:int,since_utime:int|null}>  $items
     * @return array<string,string>
     */
    public function pollBatch(array $items): array;
}
