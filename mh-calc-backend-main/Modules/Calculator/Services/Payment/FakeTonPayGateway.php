<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;

/**
 * Тестовый/dev драйвер TON Pay. Подтверждение — через статический реестр «пришедших»
 * on-chain переводов: тест регистрирует факт оплаты (memo → сумма), pollStatus сверяет
 * memo и сумму. Имитация non-custodial валидации без реальной сети.
 */
class FakeTonPayGateway implements PaymentGateway
{
    /** @var array<string,int> memo(external_ref) => пришедшая сумма в центах */
    public static array $onchain = [];

    public static function reset(): void
    {
        self::$onchain = [];
    }

    /** Тест: «деньги пришли» — зарегистрировать перевод с memo и суммой. */
    public static function fakePay(string $externalRef, int $amountCents): void
    {
        self::$onchain[$externalRef] = $amountCents;
    }

    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult
    {
        return new InvoiceResult($externalRef, 'ton://transfer/FAKE?text=' . rawurlencode($externalRef));
    }

    public function verifyAndParseWebhook(Request $request): ?WebhookEvent
    {
        return null;
    }

    public function pollStatus(string $externalRef, int $amountCents): string
    {
        if (!array_key_exists($externalRef, self::$onchain)) {
            return 'pending'; // транзакция ещё не пришла
        }

        // Семантика боевого драйвера: переплату принимаем (>=), недоплату НЕ финализируем как
        // failed — ждём верный/до-перевод (терминальный failed съел бы реальные средства).
        return self::$onchain[$externalRef] >= $amountCents ? 'paid' : 'pending';
    }
}
