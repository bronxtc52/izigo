<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Боевой драйвер Wallet Pay (приём USDT/TON внутри Telegram). Реализован по публичной
 * Store-API схеме Wallet Pay; API-ключ и webhook-секрет — из Key Vault (env
 * WALLETPAY_API_KEY / WALLETPAY_WEBHOOK_SECRET), не хардкод.
 *
 * ⚠️ NEEDS-LIVE-VERIFY (Фаза 4): точные пути эндпоинтов, имена полей и СХЕМА ПОДПИСИ
 * webhook должны быть сверены с актуальной документацией Wallet Pay и боевым ключом
 * перед включением в прод. Тесты гоняют FakeGateway; этот драйвер интеграционно не проверен.
 */
class WalletPayGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $webhookSecret,
    ) {
    }

    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult
    {
        $amount = number_format($amountCents / 100, 2, '.', '');

        $response = Http::withHeaders([
            'Wpay-Store-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post(rtrim($this->baseUrl, '/') . '/wpay/store-api/v1/order', [
            'amount' => ['currencyCode' => $currency, 'amount' => $amount],
            'description' => "IziGo {$purpose}",
            'externalId' => $externalRef,
            'timeoutSeconds' => 3600,
            'customerTelegramUserId' => $meta['telegram_id'] ?? null,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Wallet Pay: создание инвойса не удалось: ' . $response->status());
        }

        $data = $response->json('data') ?? [];
        $providerId = (string) ($data['id'] ?? '');
        $payUrl = (string) ($data['payLink'] ?? $data['directPayLink'] ?? '');
        if ($providerId === '' || $payUrl === '') {
            throw new RuntimeException('Wallet Pay: пустой ответ инвойса');
        }

        return new InvoiceResult($providerId, $payUrl);
    }

    public function verifyAndParseWebhook(Request $request): ?WebhookEvent
    {
        $body = $request->getContent();
        $signature = (string) $request->header('WalletPay-Signature', '');

        // Схема подписи Wallet Pay (NEEDS-LIVE-VERIFY): HMAC-SHA256 над
        // METHOD.path.timestamp.base64(body), результат в base64.
        $timestamp = (string) $request->header('WalletPay-Timestamp', '');
        $stringToSign = $request->method() . '.' . $request->path() . '.' . $timestamp . '.' . base64_encode($body);
        $expected = base64_encode(hash_hmac('sha256', $stringToSign, $this->webhookSecret, true));

        if ($signature === '' || !hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $payload = $data['payload'] ?? $data;
        $status = match (strtoupper((string) ($data['type'] ?? $payload['status'] ?? ''))) {
            'ORDER_PAID', 'PAID' => WebhookEvent::PAID,
            'ORDER_FAILED', 'FAILED' => WebhookEvent::FAILED,
            'ORDER_EXPIRED', 'EXPIRED' => WebhookEvent::EXPIRED,
            default => WebhookEvent::PENDING,
        };

        $amount = $payload['orderAmount']['amount'] ?? $payload['amount']['amount'] ?? '0';

        return new WebhookEvent(
            externalRef: (string) ($payload['externalId'] ?? ''),
            providerId: (string) ($payload['id'] ?? ''),
            status: $status,
            amountCents: (int) round(((float) $amount) * 100),
            raw: $data,
        );
    }

    public function pollStatus(string $externalRef, int $amountCents): string
    {
        return 'none'; // Wallet Pay подтверждается webhook'ом, не опросом
    }
}
