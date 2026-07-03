<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Modules\Calculator\Services\Payment\TonPayGateway;
use Tests\TestCase;

/**
 * Боевой парсинг приёма TON Pay (раздел L плана): pollStatus поверх toncenter v3
 * /api/v3/jetton/transfers. Http::fake синтетическим ответом — без сети и БД.
 *
 * Покрывает денежную семантику: paid (memo+сумма / переплата / plaintext-комментарий),
 * НЕ-failed при недоплате (pending — ждём верный перевод), отсутствие memo-коллизии
 * (pay:5 ⊄ pay:55), pending без нашего memo, пропуск aborted, none (не сконфигурировано),
 * error при недоступности/таймауте API (B4: сбой опроса ≠ «перевода нет»).
 */
class TonPayParsingTest extends TestCase
{
    private const BASE = 'https://toncenter.com/api/v3';
    private const MERCHANT = 'UQ_merchant';
    private const JETTON = 'EQ_usdt_master';
    private const KEY = 'test-key';

    // 18000 центов (180 USDT) × 10^4 = 180000000 мин. единиц (decimals=6).
    private const CENTS = 18000;
    private const UNITS = '180000000';

    private function gateway(): TonPayGateway
    {
        return new TonPayGateway(self::MERCHANT, self::BASE, self::KEY, self::JETTON);
    }

    /** forward_payload как hex ячейки текст-комментария: опкод 0x00000000 + ascii memo. */
    private function hexComment(string $memo): string
    {
        return '00000000' . bin2hex($memo);
    }

    private function fakeTransfers(array $transfers): void
    {
        Http::fake([
            self::BASE . '/jetton/transfers*' => Http::response(['jetton_transfers' => $transfers], 200),
        ]);
    }

    private function transfer(string $payload, string $amount, bool $aborted = false): array
    {
        return ['forward_payload' => $payload, 'amount' => $amount, 'transaction_aborted' => $aborted];
    }

    /**
     * Реальная форма toncenter v3: forward_payload = сериализованный BoC (base64), плюс готовый
     * decoded_forward_payload.comment. Матч идёт по decoded-комментарию (см. memoMatches).
     */
    private function realTransfer(string $boc, string $comment, string $amount, bool $aborted = false): array
    {
        return [
            'forward_payload' => $boc,
            'decoded_forward_payload' => ['@type' => 'text_comment', 'comment' => $comment, 'type' => 'text_comment'],
            'amount' => $amount,
            'transaction_aborted' => $aborted,
        ];
    }

    public function testPaidWhenMemoAndAmountMatch(): void
    {
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), self::UNITS)]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testPaidOnRealToncenterBocPayload(): void
    {
        // Реальный захват с mainnet (контрольный платёж pay:13, 1 USDT): forward_payload — BoC base64
        // (magic b5ee9c72), из которого commentEquals memo НЕ достаёт; матч по decoded_forward_payload.
        // Регресс на баг NEEDS-LIVE-VERIFY: до фикса этот перевод не распознавался → платёж висел pending.
        $this->fakeTransfers([
            $this->realTransfer('te6cckEBAQEADAAAFAAAAABwYXk6MTMsMiYB', 'pay:13', '1000000'),
        ]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:13', 100));
    }

    public function testDecodedCommentNoMemoCollision(): void
    {
        // Точный матч и на decoded-пути: comment "pay:55" не подтверждает заказ "pay:5".
        $this->fakeTransfers([
            $this->realTransfer('te6cckEBAQEADAAAFAAAAABwYXk6NTUsMiYB', 'pay:55', self::UNITS),
        ]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testPaidOnOverpayment(): void
    {
        // Переплату принимаем — реальные деньги пришли, заказ оплачен.
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), '200000000')]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testPaidWhenCommentIsPlainText(): void
    {
        // Индексатор отдал уже декодированный текст-комментарий.
        $this->fakeTransfers([$this->transfer('pay:5', self::UNITS)]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testUnderpaymentStaysPendingNotFailed(): void
    {
        // memo наш, но сумма меньше — НЕ failed (терминальный failed съел бы реальные средства),
        // ждём верный/до-перевод.
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), '100')]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testCorrectTransferAmongOthersConfirms(): void
    {
        // Среди переводов с нашим memo есть и недоплата, и верный — итог paid.
        $this->fakeTransfers([
            $this->transfer($this->hexComment('pay:5'), '100'),
            $this->transfer($this->hexComment('pay:5'), self::UNITS),
        ]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testNoMemoCollisionPrefix(): void
    {
        // Платёж pay:55 НЕ должен матчиться при опросе pay:5 (раньше — подстрока → коллизия).
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:55'), self::UNITS)]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testPendingWhenNoMatchingMemo(): void
    {
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:999'), self::UNITS)]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testAbortedTransferIgnored(): void
    {
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), self::UNITS, aborted: true)]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testNoneWhenUnconfigured(): void
    {
        Http::fake();
        $gw = new TonPayGateway('', self::BASE, '', '');

        $this->assertSame('none', $gw->pollStatus('pay:5', self::CENTS));
        Http::assertNothingSent();
    }

    public function testErrorWhenApiUnavailable(): void
    {
        // B4: сбой опроса — это 'error', НЕ 'pending': поллер не должен принять
        // «не смогли проверить» за «проверили, перевода нет» и экспирировать по TTL.
        Http::fake([self::BASE . '/jetton/transfers*' => Http::response('', 503)]);

        $this->assertSame('error', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testErrorWhenNetworkFails(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->assertSame('error', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    // ── MAJOR G4/1: курсор start_utime + пагинация (старый перевод не выпадает из окна-100) ──

    public function testStartUtimeCursorSentWhenSinceProvided(): void
    {
        // sinceUtime от времени создания платежа → драйвер шлёт start_utime (минус запас 3600с).
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), self::UNITS)]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS, 1_700_000_000));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'start_utime=' . (1_700_000_000 - 3600))
            && str_contains($req->url(), 'sort=asc'));
    }

    public function testCursorPaginationFindsTransferBeyondFirstPage(): void
    {
        // Всплеск: первая страница (100 переводов) — чужие memo; наш перевод только на 2-й странице.
        // Старое окно-100 его теряло → платёж вечно pending. С пагинацией по курсору — находим.
        $page0 = array_fill(0, 100, $this->transfer($this->hexComment('pay:999'), self::UNITS));
        $page1 = [$this->transfer($this->hexComment('pay:5'), self::UNITS)];
        Http::fake([
            self::BASE . '/jetton/transfers*' => Http::sequence()
                ->push(['jetton_transfers' => $page0], 200)
                ->push(['jetton_transfers' => $page1], 200),
        ]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS, 1_700_000_000));
        Http::assertSentCount(2); // страница 0 (полная) → дозапросили страницу 1
    }

    // ── MAJOR G4/2: pollBatch — ОДИН фетч списка на несколько платежей ──

    public function testPollBatchMatchesManyRefsInSingleFetch(): void
    {
        $this->fakeTransfers([
            $this->transfer($this->hexComment('pay:5'), self::UNITS),
            $this->transfer($this->hexComment('pay:7'), '100'), // недоплата → pending
        ]);

        $res = $this->gateway()->pollBatch([
            ['ref' => 'pay:5', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_000],
            ['ref' => 'pay:7', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_100],
            ['ref' => 'pay:9', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_200],
        ]);

        $this->assertSame('paid', $res['pay:5']);
        $this->assertSame('pending', $res['pay:7']); // memo наш, сумма мала — не failed
        $this->assertSame('pending', $res['pay:9']); // перевода нет
        Http::assertSentCount(1); // все три матчатся ОДНИМ запросом списка
    }

    public function testPollBatchUsesOldestSinceAsCursor(): void
    {
        // Курсор фетча = самый старый из платежей (минус запас): давний pending не выпадет.
        $this->fakeTransfers([$this->transfer($this->hexComment('pay:5'), self::UNITS)]);

        $this->gateway()->pollBatch([
            ['ref' => 'pay:5', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_009_000],
            ['ref' => 'pay:7', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_000], // старейший
        ]);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'start_utime=' . (1_700_000_000 - 3600)));
    }

    public function testPollBatchAllErrorWhenApiUnavailable(): void
    {
        // Фетч упал → 'error' по ВСЕМ ref (поллер не финализирует и не экспирирует эти платежи).
        Http::fake([self::BASE . '/jetton/transfers*' => Http::response('', 503)]);

        $res = $this->gateway()->pollBatch([
            ['ref' => 'pay:5', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_000],
            ['ref' => 'pay:7', 'amount_cents' => self::CENTS, 'since_utime' => 1_700_000_000],
        ]);

        $this->assertSame('error', $res['pay:5']);
        $this->assertSame('error', $res['pay:7']);
    }
}
