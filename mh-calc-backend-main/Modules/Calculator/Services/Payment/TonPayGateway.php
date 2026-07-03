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
    /** Размер страницы /jetton/transfers (limit toncenter v3). */
    private const PAGE_SIZE = 100;
    /**
     * Предохранитель глубины пагинации: max страниц за один фетч (PAGE_SIZE×MAX_PAGES переводов).
     * Курсор start_utime ограничивает объём временем создания платежа, это лишь верхняя отсечка
     * от «убегания» на аномальном всплеске. 50×100 = 5000 переводов.
     */
    private const MAX_PAGES = 50;
    /**
     * Запас (сек), вычитаемый из времени создания платежа при построении start_utime: перевод
     * может лечь в блок чуть раньше нашей отметки времени (лаг/расхождение часов) — берём с полем.
     */
    private const SINCE_MARGIN_SECONDS = 3600;

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

    public function pollStatus(string $externalRef, int $amountCents, ?int $sinceUtime = null): string
    {
        if (!$this->configured()) {
            return 'none'; // не сконфигурировано — нечего опрашивать
        }

        $transfers = $this->fetchTransfers($this->startUtime($sinceUtime));
        if ($transfers === null) {
            return 'error'; // опрос не удался (сеть/таймаут/5xx) — не финализируем и не экспирируем
        }

        return $this->matchTransfers($transfers, $externalRef, $amountCents);
    }

    /**
     * Пакетный опрос: ОДИН фетч списка переводов за тик (курсор — по самому старому pending),
     * локальный матч каждого memo. Устраняет N идентичных HTTP-запросов на N pending-платежей.
     */
    public function pollBatch(array $items): array
    {
        $result = [];
        if ($items === []) {
            return $result;
        }
        if (!$this->configured()) {
            foreach ($items as $it) {
                $result[$it['ref']] = 'none';
            }

            return $result;
        }

        // Курсор фетча = самый старый из платежей (с запасом): гарантирует, что перевод по
        // давнему pending попадёт в выборку. null-since среди платежей → курсора нет (весь хвост).
        $sinces = array_map(static fn ($it) => $it['since_utime'] ?? null, $items);
        $minSince = in_array(null, $sinces, true) ? null : min($sinces);

        $transfers = $this->fetchTransfers($this->startUtime($minSince));
        if ($transfers === null) {
            foreach ($items as $it) {
                $result[$it['ref']] = 'error'; // фетч упал → 'error' по всем (не экспирируем)
            }

            return $result;
        }

        foreach ($items as $it) {
            $result[$it['ref']] = $this->matchTransfers($transfers, $it['ref'], (int) $it['amount_cents']);
        }

        return $result;
    }

    private function configured(): bool
    {
        return $this->merchantAddress !== '' && $this->apiKey !== '' && $this->jettonMaster !== '';
    }

    /** start_utime для toncenter: время создания платежа минус запас; null при отсутствии курсора. */
    private function startUtime(?int $sinceUtime): ?int
    {
        if ($sinceUtime === null) {
            return null;
        }

        return max(0, $sinceUtime - self::SINCE_MARGIN_SECONDS);
    }

    /**
     * Забрать входящие jetton-переводы на merchant-адрес, страницами, начиная с курсора start_utime
     * (если задан). Возвращает массив переводов, либо null при сбое запроса (сеть/таймаут/не-2xx) —
     * чтобы отличить «не смогли проверить» от «проверили, перевода нет».
     *
     * С курсором идём по возрастанию времени (sort=asc): новые переводы дописываются в хвост, поэтому
     * offset-пагинация устойчива (окно не «съезжает»). Без курсора — по убыванию (свежие сверху),
     * первые страницы. MAX_PAGES — предохранитель от бесконечного хвоста.
     */
    private function fetchTransfers(?int $startUtime): ?array
    {
        $all = [];
        $sort = $startUtime === null ? 'desc' : 'asc';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = [
                'owner_address' => $this->merchantAddress,
                'jetton_master' => $this->jettonMaster,
                'direction' => 'in',
                'limit' => self::PAGE_SIZE,
                'offset' => $page * self::PAGE_SIZE,
                'sort' => $sort,
            ];
            if ($startUtime !== null) {
                $query['start_utime'] = $startUtime; // toncenter v3: нижняя граница по времени tx
            }

            try {
                $response = Http::withHeaders(['X-Api-Key' => $this->apiKey])
                    ->timeout(self::HTTP_TIMEOUT)
                    ->get(rtrim($this->apiBaseUrl, '/') . '/jetton/transfers', $query);
            } catch (\Throwable $e) {
                return null; // сетевой сбой/таймаут — весь фетч не удался
            }
            if (!$response->successful()) {
                return null; // недоступность API — весь фетч не удался
            }

            $chunk = $response->json('jetton_transfers') ?? [];
            foreach ($chunk as $tx) {
                $all[] = $tx;
            }
            if (count($chunk) < self::PAGE_SIZE) {
                break; // последняя страница
            }
        }

        return $all;
    }

    /** Матч одного memo по списку переводов: paid при верной сумме, иначе pending. */
    private function matchTransfers(array $transfers, string $externalRef, int $amountCents): string
    {
        $expectedUnits = (string) ($amountCents * self::CENTS_TO_UNITS);
        foreach ($transfers as $tx) {
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
