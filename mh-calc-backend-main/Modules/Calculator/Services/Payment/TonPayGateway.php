<?php

namespace Modules\Calculator\Services\Payment;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
    /** Дефолт размера страницы /jetton/transfers (limit toncenter v3), если не задан конфигом. */
    private const DEFAULT_PAGE_SIZE = 100;
    /**
     * Дефолт МЯГКОГО предела глубины пагинации (страниц за один фетч), если не задан конфигом.
     * Раньше был жёстким const MAX_PAGES=50 (потолок 5000 переводов): при sort=asc от старейшего
     * pending матчащий перевод за 51-й страницей молча терялся → платёж вечно pending. Теперь
     * пагинируем ДО короткой страницы (chunk<pageSize) — окно сканируется целиком; предел лишь
     * страховка от «убегания» на аномальном всплеске, вынесен в config и поднят.
     */
    private const DEFAULT_MAX_PAGES = 200;
    /**
     * Запас (сек), вычитаемый из времени создания платежа при построении start_utime: перевод
     * может лечь в блок чуть раньше нашей отметки времени (лаг/расхождение часов) — берём с полем.
     */
    private const SINCE_MARGIN_SECONDS = 3600;

    /** Достигнут ли мягкий предел пагинации в последнем fetchTransfers (окно не досканировано). */
    private bool $lastFetchTruncated = false;

    public function __construct(
        private readonly string $merchantAddress,
        private readonly string $apiBaseUrl,    // toncenter v3, напр. https://toncenter.com/api/v3
        private readonly string $apiKey,
        private readonly string $jettonMaster,  // мастер-контракт USDT
        private readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        private readonly int $maxPages = self::DEFAULT_MAX_PAGES,
    ) {
    }

    /** Эффективный размер страницы (пол 1). */
    private function pageSize(): int
    {
        return max(1, $this->pageSize);
    }

    /** Эффективный мягкий предел числа страниц (пол 1). */
    private function maxPages(): int
    {
        return max(1, $this->maxPages);
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

        $status = $this->matchTransfers($transfers, $externalRef, $amountCents);
        if ($status === 'pending' && $this->lastFetchTruncated) {
            // Мягкий предел пагинации исчерпан, а совпадения нет — возможен незамеченный перевод
            // за пределом окна. НЕ тихий 'pending': сигналим в лог + Sentry для ручного разбора.
            $this->warnTruncated([$externalRef]);
        }

        return $status;
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

        // Предел пагинации исчерпан, а часть платежей осталась без совпадения — их перевод мог
        // лежать за пределом досканированного окна: сигналим наблюдаемостью (не молчим).
        if ($this->lastFetchTruncated) {
            $unresolved = array_keys(array_filter($result, static fn ($s) => $s === 'pending'));
            if ($unresolved !== []) {
                $this->warnTruncated($unresolved);
            }
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
     * первые страницы.
     *
     * Пагинируем ДО короткой страницы (chunk<pageSize) — окно сканируется ЦЕЛИКОМ, жёсткого потолка
     * нет. maxPages — лишь мягкий страховочный предел от «убегания» на аномальном всплеске; при его
     * достижении с полной последней страницей окно НЕ досканировано — ставим lastFetchTruncated,
     * чтобы вызывающий просигналил наблюдаемостью, а не отдал молчаливый 'pending'.
     */
    private function fetchTransfers(?int $startUtime): ?array
    {
        $this->lastFetchTruncated = false;
        $all = [];
        $pageSize = $this->pageSize();
        $maxPages = $this->maxPages();
        $sort = $startUtime === null ? 'desc' : 'asc';
        for ($page = 0; $page < $maxPages; $page++) {
            $query = [
                'owner_address' => $this->merchantAddress,
                'jetton_master' => $this->jettonMaster,
                'direction' => 'in',
                'limit' => $pageSize,
                'offset' => $page * $pageSize,
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
            if (count($chunk) < $pageSize) {
                return $all; // последняя (короткая) страница — окно досканировано целиком
            }
        }

        // Вышли по мягкому пределу с полной последней страницей — окно, возможно, не досканировано.
        $this->lastFetchTruncated = true;

        return $all;
    }

    /** Наблюдаемость: мягкий предел пагинации исчерпан, а совпадения нет — сигналим для разбора. */
    private function warnTruncated(array $unresolvedRefs): void
    {
        Log::warning('tonpay: достигнут мягкий предел пагинации окна матчинга без совпадения', [
            'max_pages' => $this->maxPages(),
            'page_size' => $this->pageSize(),
            'unresolved_refs' => $unresolvedRefs,
        ]);
        \Sentry\captureMessage(
            sprintf(
                'TonPay: окно матчинга исчерпало предел пагинации (%d стр × %d) без совпадения — возможен незамеченный перевод (refs: %s%s)',
                $this->maxPages(),
                $this->pageSize(),
                implode(', ', array_slice($unresolvedRefs, 0, 10)),
                count($unresolvedRefs) > 10 ? ', …' : ''
            ),
            \Sentry\Severity::warning()
        );
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
