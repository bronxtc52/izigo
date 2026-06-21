<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Боевой драйвер приёма TON Pay (non-custodial). Деньги идут НАПРЯМУЮ на наш merchant
 * TON-адрес; подтверждение — самостоятельная валидация on-chain опросом TON API (toncenter):
 * ищем входящую транзакцию на наш адрес с comment(memo)=external_ref и суммой ≈ amount.
 *
 * createInvoice здесь не «создаёт инвойс у процессора», а описывает реквизиты перевода
 * (адрес+memo+сумма) — собственно подпись/отправку делает кошелёк через TON Pay UI на фронте.
 *
 * ⚠️ NEEDS-LIVE-VERIFY (Фаза 4): точный эндпоинт/формат TON API, парсинг входящих jetton-переводов
 * USDT (jetton-master, decimals=6), сопоставление memo и суммы, число подтверждений — проверить
 * на тестнете с реальным адресом ДО прода. Тесты гоняют FakeTonPayGateway.
 */
class TonPayGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $merchantAddress,
        private readonly string $apiBaseUrl,
        private readonly string $apiKey,
    ) {
    }

    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult
    {
        // Реквизиты перевода: наш адрес + memo(external_ref). Фронт (TON Pay UI) строит
        // фактический jetton-transfer USDT; deeplink — запасной вариант.
        $payUrl = 'ton://transfer/' . $this->merchantAddress . '?text=' . rawurlencode($externalRef);

        return new InvoiceResult($externalRef, $payUrl);
    }

    public function verifyAndParseWebhook(Request $request): ?WebhookEvent
    {
        return null; // у non-custodial TON Pay webhook'а процессора нет — подтверждаем опросом
    }

    public function pollStatus(string $externalRef, int $amountCents): string
    {
        if ($this->merchantAddress === '' || $this->apiKey === '') {
            return 'none'; // не сконфигурировано — нечего опрашивать
        }

        // NEEDS-LIVE-VERIFY: ниже — каркас опроса toncenter; формат ответа/поля сверить вживую.
        $response = Http::withHeaders(['X-API-Key' => $this->apiKey])
            ->get(rtrim($this->apiBaseUrl, '/') . '/getTransactions', [
                'address' => $this->merchantAddress,
                'limit' => 50,
            ]);

        if (!$response->successful()) {
            return 'pending'; // временная недоступность API — не финализируем
        }

        foreach (($response->json('result') ?? []) as $tx) {
            $in = $tx['in_msg'] ?? [];
            $comment = (string) ($in['message'] ?? $in['comment'] ?? '');
            if ($comment !== $externalRef) {
                continue;
            }
            // value входящего сообщения в нативном TON — в нанотонах; для USDT-джеттона сумма
            // парсится из тела jetton-transfer (decimals=6). NEEDS-LIVE-VERIFY: точный парсинг.
            $matched = $this->amountMatches($in, $amountCents);

            return $matched ? 'paid' : 'failed';
        }

        return 'pending'; // транзакция с нашим memo пока не найдена
    }

    /** NEEDS-LIVE-VERIFY: сверка суммы входящего перевода с ожидаемой (USDT decimals=6). */
    private function amountMatches(array $inMsg, int $amountCents): bool
    {
        // Заглушка боевого парсинга суммы jetton-перевода — реализовать с живым форматом.
        return false;
    }
}
