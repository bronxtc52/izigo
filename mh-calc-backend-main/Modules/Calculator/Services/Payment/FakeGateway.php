<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;

/**
 * Тестовый/dev платёжный шлюз. createInvoice детерминирован; webhook проверяется простой
 * HMAC-подписью тела по общему секрету (config calculator.walletpay_webhook_secret),
 * чтобы тесты могли воспроизвести валидный/невалидный callback без внешнего API.
 */
class FakeGateway implements PaymentGateway
{
    public function __construct(private readonly string $webhookSecret)
    {
    }

    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult
    {
        $providerId = 'fake_' . substr(hash('sha256', $externalRef), 0, 16);

        return new InvoiceResult($providerId, "https://fake.pay/{$providerId}");
    }

    public function verifyAndParseWebhook(Request $request): ?WebhookEvent
    {
        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $this->webhookSecret);
        $given = (string) $request->header('X-Fake-Signature', '');
        if (!hash_equals($expected, $given)) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['external_ref'], $data['status'])) {
            return null;
        }

        return new WebhookEvent(
            externalRef: (string) $data['external_ref'],
            providerId: (string) ($data['provider_id'] ?? ''),
            status: (string) $data['status'],
            amountCents: (int) ($data['amount_cents'] ?? 0),
            raw: $data,
        );
    }

    public function pollStatus(string $externalRef, int $amountCents): string
    {
        return 'none'; // webhook-драйвер не опрашивается
    }
}
