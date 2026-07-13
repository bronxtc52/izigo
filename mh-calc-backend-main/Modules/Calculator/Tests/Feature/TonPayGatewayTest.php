<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\ActivationEvent;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\Payment\PaymentGateway;
use Modules\Calculator\Services\Payment\TonPayGateway;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B1 (P2-hardening): снятие остаточного потолка окна матчинга TON. Раньше жёсткий const
 * MAX_PAGES=50 обрывал fetchTransfers на 50-й странице (потолок 5000 переводов): при sort=asc
 * от старейшего pending матчащий перевод за 51-й страницей молча терялся → matchTransfers
 * 'pending' → платёж вечно висел. Теперь пагинация идёт ДО короткой страницы (окно целиком),
 * мягкий предел вынесен в config; при исчерпании предела без совпадения — Log::warning + Sentry.
 *
 * Http::fake синтетическим ответом toncenter v3 /jetton/transfers — без сети и (в основном) БД.
 */
class TonPayGatewayTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const BASE = 'https://toncenter.com/api/v3';
    private const MERCHANT = 'UQ_merchant';
    private const JETTON = 'EQ_usdt_master';
    private const KEY = 'test-key';

    // 18000 центов (180 USDT) × 10^4 = 180000000 мин. единиц (decimals=6).
    private const CENTS = 18000;
    private const UNITS = '180000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Дефолтный боевой драйвер (config-дефолты пагинации: page 100, max 200). */
    private function gateway(): TonPayGateway
    {
        return new TonPayGateway(self::MERCHANT, self::BASE, self::KEY, self::JETTON);
    }

    /** forward_payload как hex ячейки текст-комментария: опкод 0x00000000 + ascii memo. */
    private function hexComment(string $memo): string
    {
        return '00000000' . bin2hex($memo);
    }

    private function transfer(string $memo, string $amount): array
    {
        return ['forward_payload' => $this->hexComment($memo), 'amount' => $amount, 'transaction_aborted' => false];
    }

    private function fakeTransfers(array $transfers): void
    {
        Http::fake([
            self::BASE . '/jetton/transfers*' => Http::response(['jetton_transfers' => $transfers], 200),
        ]);
    }

    // ── B1 REPRO: матчащий перевод за старым потолком 50 страниц находится ──

    public function testMatchBeyondOldFiftyPageCeilingIsFound(): void
    {
        // 50 ПОЛНЫХ страниц (по 100) чужих memo + матчащий перевод на 51-й странице.
        // На старом коде (жёсткий MAX_PAGES=50) fetchTransfers обрывался на 50-й → 'pending'
        // (платёж терялся). После фикса окно сканируется целиком → 'paid'.
        $fullPage = array_fill(0, 100, $this->transfer('pay:999', self::UNITS));
        $seq = Http::sequence();
        for ($i = 0; $i < 50; $i++) {
            $seq->push(['jetton_transfers' => $fullPage], 200);
        }
        $seq->push(['jetton_transfers' => [$this->transfer('pay:5', self::UNITS)]], 200);
        $seq->whenEmpty(Http::response(['jetton_transfers' => []], 200));
        Http::fake([self::BASE . '/jetton/transfers*' => $seq]);

        // sinceUtime задан → sort=asc (боевой путь курсора от старейшего pending).
        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS, 1_700_000_000));
        Http::assertSentCount(51); // 50 полных страниц + 51-я с матчем (короткая) → стоп
    }

    // ── Регресс денежной семантики (не сломать при снятии потолка) ──

    public function testExactMemoNoCollision(): void
    {
        // pay:55 НЕ подтверждает заказ pay:5 (точный матч, не подстрока).
        $this->fakeTransfers([$this->transfer('pay:55', self::UNITS)]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testUnderpaymentStaysPending(): void
    {
        $this->fakeTransfers([$this->transfer('pay:5', '100')]);

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testOverpaymentIsPaid(): void
    {
        $this->fakeTransfers([$this->transfer('pay:5', '200000000')]);

        $this->assertSame('paid', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    public function testFetchFailureIsError(): void
    {
        // Сбой опроса (5xx) → 'error', НЕ 'pending' и НЕ 'paid' (поллер не экспирирует и не теряет).
        Http::fake([self::BASE . '/jetton/transfers*' => Http::response('', 503)]);

        $this->assertSame('error', $this->gateway()->pollStatus('pay:5', self::CENTS));
    }

    // ── Наблюдаемость: предел исчерпан без совпадения → Log::warning (+ Sentry) ──

    public function testWarnsWhenPaginationLimitReachedWithoutMatch(): void
    {
        // Драйвер с крошечным мягким пределом (2 стр × 2): обе страницы полные, совпадения нет —
        // окно НЕ досканировано, вызывающий получает 'pending' + сигнал в лог/Sentry (не молчит).
        $fullPage = [$this->transfer('pay:999', self::UNITS), $this->transfer('pay:998', self::UNITS)];
        Http::fake([
            self::BASE . '/jetton/transfers*' => Http::response(['jetton_transfers' => $fullPage], 200),
        ]);
        Log::spy();

        $gw = new TonPayGateway(self::MERCHANT, self::BASE, self::KEY, self::JETTON, pageSize: 2, maxPages: 2);
        $this->assertSame('pending', $gw->pollStatus('pay:5', self::CENTS, 1_700_000_000));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($msg, $ctx = []) => str_contains((string) $msg, 'предел пагинации')
                && in_array('pay:5', $ctx['unresolved_refs'] ?? [], true)
        );
    }

    public function testNoWarnWhenWindowFullyScannedShortPage(): void
    {
        // Короткая последняя страница = окно досканировано целиком → предупреждения нет.
        $this->fakeTransfers([$this->transfer('pay:999', self::UNITS)]); // 1 tx < pageSize → короткая
        Log::spy();

        $this->assertSame('pending', $this->gateway()->pollStatus('pay:5', self::CENTS, 1_700_000_000));

        Log::shouldNotHaveReceived('warning');
    }

    // ── Идемпотентность приёма: тот же перевод в два тика → активация ровно одна ──

    public function testPollIdempotentAcrossTwoTicksWithRealGateway(): void
    {
        // Боевой драйвер (ton_pay) + Http::fake матчащего перевода: два прогона poll не задваивают
        // подтверждение и активацию (confirmPayment идемпотентен, activate — по ключу order:{id}).
        config([
            'calculator.payment_gateway' => 'ton_pay',
            'calculator.ton_merchant_address' => self::MERCHANT,
            'calculator.ton_api_key' => self::KEY,
            'calculator.ton_usdt_jetton_master' => self::JETTON,
            'calculator.ton_api_v3_base_url' => self::BASE,
        ]);
        $this->app->forgetInstance(PaymentGateway::class);

        $product = Product::query()->create([
            'name' => 'Silver', 'price_usdt_cents' => self::CENTS, 'pv' => 180,
            'package_id' => 2, 'sku' => 'TARIFF-SILVER', 'is_active' => true, 'sort' => 2,
        ]);
        [$data] = $this->registerTg(2000, name: 'Buyer');

        $orderId = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $product->id], $this->tgHeaders($data))
            ->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        // Деньги «пришли» on-chain с верным memo и суммой.
        $this->fakeTransfers([$this->transfer($pay['memo'], self::UNITS)]);

        $this->artisan('commerce:tonpay-poll')->assertExitCode(0);
        $this->artisan('commerce:tonpay-poll')->assertExitCode(0); // повтор не должен задваивать

        $this->assertSame(Payment::STATUS_PAID, Payment::find($pay['payment_id'])->status);
        $this->assertSame(Order::STATUS_PAID, Order::find($orderId)->status);
        // Активация ровно одна (ключ order:{id}).
        $this->assertSame(1, ActivationEvent::query()->where('idempotency_key', "order:{$orderId}")->count());
    }
}
