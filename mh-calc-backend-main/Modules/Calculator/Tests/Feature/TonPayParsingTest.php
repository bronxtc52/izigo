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
 * pending при недоступности/таймауте API.
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

    public function testPendingWhenApiUnavailable(): void
    {
        Http::fake([self::BASE . '/jetton/transfers*' => Http::response('', 503)]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }
}
