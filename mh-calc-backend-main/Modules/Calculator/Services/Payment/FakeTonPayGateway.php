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

    /** @var array<string,bool> memo => опрос по нему «падает» (эмуляция сбоя сети/API) */
    public static array $failMemos = [];

    /** Все опросы «падают» (эмуляция полной недоступности индексатора). */
    public static bool $failAll = false;

    public static function reset(): void
    {
        self::$onchain = [];
        self::$failMemos = [];
        self::$failAll = false;
    }

    /** Тест: «деньги пришли» — зарегистрировать перевод с memo и суммой. */
    public static function fakePay(string $externalRef, int $amountCents): void
    {
        self::$onchain[$externalRef] = $amountCents;
    }

    /** Тест: опрос по этому memo завершается ошибкой сети/API ('error'). */
    public static function failFor(string $externalRef): void
    {
        self::$failMemos[$externalRef] = true;
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
        if (self::$failAll || (self::$failMemos[$externalRef] ?? false)) {
            return 'error'; // семантика боевого драйвера: опрос не удался
        }

        if (!array_key_exists($externalRef, self::$onchain)) {
            return 'pending'; // транзакция ещё не пришла
        }

        // Семантика боевого драйвера: переплату принимаем (>=), недоплату НЕ финализируем как
        // failed — ждём верный/до-перевод (терминальный failed съел бы реальные средства).
        return self::$onchain[$externalRef] >= $amountCents ? 'paid' : 'pending';
    }
}
