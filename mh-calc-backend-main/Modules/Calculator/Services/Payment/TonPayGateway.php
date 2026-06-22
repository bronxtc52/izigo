<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Боевой драйвер приёма TON Pay (non-custodial). Деньги идут НАПРЯМУЮ на наш merchant
 * TON-адрес; подтверждение — самостоятельная валидация on-chain опросом TON API
 * (toncenter v3 /jetton/transfers): берём входящие jetton-переводы на наш адрес и ищем перевод
 * с forward_payload(memo)=external_ref и суммой >= ожидаемой (USDT, decimals=6).
 *
 * createInvoice не «создаёт инвойс у процессора», а описывает реквизиты перевода (адрес+memo+сумма) —
 * подпись/отправку делает кошелёк через TON Pay UI на фронте. deeplink — запасной вариант.
 *
 * Денежная семантика (важно):
 * - memo сравниваем ТОЧНО (не подстрокой): pay:5 ⊄ pay:55 — иначе коллизия платежей.
 * - по несовпадению/недоплате НЕ финализируем как failed — возвращаем pending и ждём верный перевод
 *   (терминальный failed «съел» бы реальные пришедшие средства). Следствие: платёж без подходящего
 *   перевода висит в pending бессрочно — авто-экспирация (TTL) пока НЕ реализована (отдельный TODO).
 * - переплату принимаем (amount >= ожидаемого), чтобы не терять реально пришедшие деньги.
 *
 * Форма forward_payload сверена на mainnet (контрольный платёж 2026-06-22, pay:13): toncenter v3
 * отдаёт его сериализованным BoC (base64) + готовый decoded_forward_payload.comment — матчим по
 * последнему (см. memoMatches). Остаётся открытым: глубина подтверждений и авто-экспирация pending (TTL).
 */
class TonPayGateway implements PaymentGateway
{
    /** USDT-джеттон в сети TON — 6 знаков. Центы (1/100 USDT) → мин. единицы джеттона: ×10^4. */
    private const CENTS_TO_UNITS = 10000;
    private const HTTP_TIMEOUT = 10;
    private const POLL_LIMIT = 100;

    public function __construct(
        private readonly string $merchantAddress,
        private readonly string $apiBaseUrl,    // toncenter v3, напр. https://toncenter.com/api/v3
        private readonly string $apiKey,
        private readonly string $jettonMaster,  // мастер-контракт USDT
    ) {
    }

    public function createInvoice(int $amountCents, string $currency, string $purpose, string $externalRef, array $meta = []): InvoiceResult
    {
        $payUrl = 'ton://transfer/' . $this->merchantAddress . '?text=' . rawurlencode($externalRef);

        return new InvoiceResult($externalRef, $payUrl);
    }

    public function verifyAndParseWebhook(Request $request): ?WebhookEvent
    {
        return null; // у non-custodial TON Pay webhook'а процессора нет — подтверждаем опросом
    }

    public function pollStatus(string $externalRef, int $amountCents): string
    {
        if ($this->merchantAddress === '' || $this->apiKey === '' || $this->jettonMaster === '') {
            return 'none'; // не сконфигурировано — нечего опрашивать
        }

        try {
            $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
                ->timeout(self::HTTP_TIMEOUT)
                ->get(rtrim($this->apiBaseUrl, '/') . '/jetton/transfers', [
                    'owner_address' => $this->merchantAddress,
                    'jetton_master' => $this->jettonMaster,
                    'direction' => 'in',
                    'limit' => self::POLL_LIMIT,
                ]);
        } catch (\Throwable $e) {
            return 'pending'; // сетевой сбой/таймаут — не финализируем
        }

        if (!$response->successful()) {
            return 'pending'; // временная недоступность API — не финализируем
        }

        $expectedUnits = (string) ($amountCents * self::CENTS_TO_UNITS);
        foreach (($response->json('jetton_transfers') ?? []) as $tx) {
            if (($tx['transaction_aborted'] ?? false) === true) {
                continue;
            }
            if (!$this->memoMatches($tx, $externalRef)) {
                continue; // не наш memo (точное совпадение, без подстрок)
            }
            if ($this->amountSufficient((string) ($tx['amount'] ?? ''), $expectedUnits)) {
                return 'paid'; // перевод по нашему memo на ожидаемую сумму (или больше)
            }
            // memo наш, но сумма меньше — НЕ failed: ждём верный/до-перевод, продолжаем перебор.
        }

        return 'pending'; // подходящий перевод пока не найден
    }

    /**
     * Точное совпадение memo перевода с external_ref. Приоритет — готовый текст-комментарий из
     * toncenter v3 (decoded_forward_payload.comment): на mainnet реальный forward_payload приходит
     * сериализованным BoC (base64, magic b5ee9c72 → "te6cck…"), из которого commentEquals memo НЕ
     * достаёт (ждал опкод 0x00000000 у сырой ячейки, а тут конверт BoC). commentEquals оставлен
     * fallback'ом для альтернативных форм индексатора (готовый текст / hex / base64 сырой ячейки).
     * Подтверждено контрольным платежом на mainnet 2026-06-22 (pay:13).
     */
    private function memoMatches(array $tx, string $externalRef): bool
    {
        $decoded = $tx['decoded_forward_payload'] ?? null;
        if (is_array($decoded)
            && isset($decoded['comment'])
            && is_string($decoded['comment'])
            && $decoded['comment'] === $externalRef) {
            return true;
        }

        $payload = $tx['forward_payload'] ?? null;

        return is_string($payload) && $this->commentEquals($payload, $externalRef);
    }

    /**
     * Точное (НЕ подстрочное) сравнение memo с текст-комментарием forward_payload. Подстрока опасна:
     * pay:5 ⊂ pay:55 → коллизия. Извлекаем комментарий во всех вероятных представлениях (готовый текст /
     * hex ячейки / base64 ячейки; с опкодом text-comment 0x00000000 и без) и сравниваем целиком.
     */
    private function commentEquals(string $forwardPayload, string $memo): bool
    {
        if ($forwardPayload === '' || $memo === '') {
            return false;
        }
        foreach ($this->commentCandidates($forwardPayload) as $candidate) {
            if ($candidate === $memo) {
                return true;
            }
        }

        return false;
    }

    /** Возможные декодировки forward_payload в строку-комментарий (для точного сравнения). */
    private function commentCandidates(string $forwardPayload): array
    {
        $out = [$forwardPayload]; // уже декодированный текст

        $bins = [];
        if (strlen($forwardPayload) % 2 === 0 && ctype_xdigit($forwardPayload)) {
            $bins[] = hex2bin($forwardPayload);
        }
        $b64 = base64_decode($forwardPayload, true);
        if ($b64 !== false && $b64 !== '') {
            $bins[] = $b64;
        }

        foreach ($bins as $bin) {
            if (!is_string($bin) || $bin === '') {
                continue;
            }
            $out[] = $bin; // сырые байты (текст без опкода)
            if (strlen($bin) >= 4 && substr($bin, 0, 4) === "\x00\x00\x00\x00") {
                $out[] = substr($bin, 4); // text-comment: опкод 0x00000000 + UTF-8
            }
        }

        return $out;
    }

    /** Сумма перевода (мин. единицы) >= ожидаемой. Переплату принимаем (не теряем средства). */
    private function amountSufficient(string $amountUnits, string $expectedUnits): bool
    {
        $amountUnits = ltrim(trim($amountUnits), '+');
        if ($amountUnits === '' || !ctype_digit($amountUnits)) {
            return false;
        }

        return $this->cmpInt($amountUnits, $expectedUnits) >= 0;
    }

    /** Сравнение неотрицательных целых-строк: -1/0/1. Без int-переполнения и bcmath. */
    private function cmpInt(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }
}
