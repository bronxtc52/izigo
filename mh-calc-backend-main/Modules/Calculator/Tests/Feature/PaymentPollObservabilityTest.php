<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Services\PaymentService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * t2 (P2-tails): персистентная наблюдаемость опроса платежей.
 *  - poll_error_streak инкрементится на 'error' и сбрасывается любым успешным опросом;
 *  - порог PAYMENT_POLL_ERROR_THRESHOLD → ОДНО событие эскалации на страйк (без re-fire);
 *  - порог 0 = эскалация выключена; cap = эскалация, НЕ авто-экспирация;
 *  - инвариант B4: payments.status никогда не принимает 'error';
 *  - админ-recheck пишет исход через тот же write-path (успех сбрасывает streak);
 *  - GET /admin/payments отдаёт поля наблюдаемости + фильтр poll_problem.
 * Sentry в тестах no-op (DSN пуст) — эскалация ассертится через Log (см. план Гейта A).
 */
class PaymentPollObservabilityTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private const ESCALATION_MSG = 'tonpay-poll: эскалация — платежи с >= N ошибками опроса подряд';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config(['calculator.payment_gateway' => 'ton_pay_fake']);
        FakeTonPayGateway::reset();
    }

    protected function tearDown(): void
    {
        FakeTonPayGateway::reset();
        parent::tearDown();
    }

    private function makeProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Silver', 'price_usdt_cents' => 18000, 'pv' => 180,
            'package_id' => 2, 'sku' => 'TARIFF-SILVER', 'is_active' => true, 'sort' => 2,
        ]);
    }

    private function payOrder(string $data, int $productId): array
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))
            ->assertOk()->json('data');

        return ['order_id' => $orderId, 'payment_id' => $pay['payment_id'], 'memo' => $pay['memo'], 'amount' => $pay['amount_cents']];
    }

    private function poll(): array
    {
        return app(PaymentService::class)->pollPending();
    }

    // --- streak: инкремент / сброс / B4 ---

    public function testErrorStreakAccumulatesAndStatusStaysPending(): void
    {
        // [B4, obligatory negative] Порог N=3, ошибок больше порога (5 тиков): статус
        // платежа НИКОГДА не становится 'error'/'failed'/'expired' — остаётся PENDING,
        // а вся история сбоя живёт в колонках наблюдаемости.
        config(['calculator.payment_poll_error_threshold' => 3]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3000, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);

        for ($i = 0; $i < 5; $i++) {
            $this->poll();
        }

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(5, $payment->poll_error_streak);
        $this->assertSame('error', $payment->last_poll_result);
        $this->assertNotNull($payment->last_polled_at);
    }

    public function testCapDoesNotAutoExpireErroredPaymentAboveThreshold(): void
    {
        // [деньги, obligatory negative] Errored-платёж старше TTL и выше порога — после
        // тика всё равно PENDING (не EXPIRED): cap = эскалация, не экспирация.
        config([
            'calculator.payment_poll_error_threshold' => 2,
            'calculator.payment_pending_ttl_minutes' => 30,
        ]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3010, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        Payment::where('id', $ctx['payment_id'])->update(['created_at' => now()->subHour()]);
        FakeTonPayGateway::failFor($ctx['memo']);

        for ($i = 0; $i < 4; $i++) {
            $this->poll();
        }

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame(4, $payment->poll_error_streak);
    }

    public function testLatePaymentAfterStreakConfirmsAndResetsStreak(): void
    {
        // [деньги] Cap не закрыл окно подхвата поздней оплаты: после страйка ошибок деньги
        // нашлись → следующий тик подтверждает платёж, fulfillment проходит, streak обнулён.
        config(['calculator.payment_poll_error_threshold' => 2]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3020, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);
        for ($i = 0; $i < 3; $i++) {
            $this->poll();
        }

        FakeTonPayGateway::reset();
        FakeTonPayGateway::fakePay($ctx['memo'], $ctx['amount']);
        $summary = $this->poll();

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(1, $summary['confirmed']);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(Order::STATUS_PAID, Order::find($ctx['order_id'])->status);
        $this->assertSame(0, $payment->poll_error_streak);
        $this->assertSame('paid', $payment->last_poll_result);
    }

    public function testSuccessfulPollResetsStreakAndTtlExpiresStaleAfterward(): void
    {
        // Сброс: failFor снят, перевода нет → тик пишет 'pending', streak=0; после этого
        // stale-платёж штатно экспирируется TTL на успешном опросе (контроль против
        // регрессии testSuccessfulPollStillExpiresStale).
        config(['calculator.payment_poll_error_threshold' => 10]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3030, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);
        $this->poll();
        $this->poll();
        $this->assertSame(2, Payment::find($ctx['payment_id'])->poll_error_streak);

        FakeTonPayGateway::reset();
        $this->poll();

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(0, $payment->poll_error_streak);
        $this->assertSame('pending', $payment->last_poll_result);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);

        // Теперь stale + успешный опрос → штатная TTL-экспирация не сломана.
        config(['calculator.payment_pending_ttl_minutes' => 30]);
        Payment::where('id', $ctx['payment_id'])->update(['created_at' => now()->subHour()]);
        $this->poll();
        $this->assertSame(Payment::STATUS_EXPIRED, Payment::find($ctx['payment_id'])->status);
    }

    public function testNoneOutcomeStoredAsNone(): void
    {
        // Амендмент Гейта A: 'none' (webhook-драйвер не опрашивается) хранится как 'none',
        // не схлопывается в 'pending'; это успешный опрос — streak сбрасывается.
        config(['calculator.payment_gateway' => 'fake']); // FakeGateway.pollBatch → 'none'
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3040, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        Payment::where('id', $ctx['payment_id'])->update(['poll_error_streak' => 4, 'last_poll_result' => 'error']);

        $this->poll();

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame('none', $payment->last_poll_result);
        $this->assertSame(0, $payment->poll_error_streak);
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
    }

    // --- эскалация порога ---

    public function testEscalationFiresExactlyOncePerStreak(): void
    {
        config(['calculator.payment_poll_error_threshold' => 3]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3050, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);

        Log::spy();

        $this->assertSame(0, $this->poll()['escalated']); // streak 1
        $this->assertSame(0, $this->poll()['escalated']); // streak 2
        $this->assertSame(1, $this->poll()['escalated']); // streak 3 — пересечение порога
        Log::shouldHaveReceived('warning')->with(self::ESCALATION_MSG, \Mockery::type('array'))->once();

        $this->assertSame(0, $this->poll()['escalated']); // streak 4 — БЕЗ re-fire
        Log::shouldHaveReceived('warning')->with(self::ESCALATION_MSG, \Mockery::type('array'))->once();
    }

    public function testAllPollsFailedIncrementsAllAndEscalatesAggregated(): void
    {
        // Индексатор лёг целиком: счётчики ВСЕХ pending инкрементятся, TTL пропущен,
        // эскалация — ОДНИМ агрегированным событием на тик (не по-платёжно).
        config([
            'calculator.payment_poll_error_threshold' => 1,
            'calculator.payment_pending_ttl_minutes' => 30,
        ]);
        $p = $this->makeProduct();
        [$aData] = $this->registerTg(3060, name: 'A');
        [$bData] = $this->registerTg(3061, name: 'B');
        $one = $this->payOrder($aData, $p->id);
        $two = $this->payOrder($bData, $p->id);
        Payment::whereIn('id', [$one['payment_id'], $two['payment_id']])
            ->update(['created_at' => now()->subHour()]);
        FakeTonPayGateway::$failAll = true;

        Log::spy();
        $summary = $this->poll();

        $this->assertSame(2, $summary['errors']);
        $this->assertSame(2, $summary['escalated']); // оба пересекли порог 1 этим тиком
        $this->assertSame(0, $summary['expired']);   // TTL пропущен (guard allPollsFailed)
        $this->assertSame(1, Payment::find($one['payment_id'])->poll_error_streak);
        $this->assertSame(1, Payment::find($two['payment_id'])->poll_error_streak);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($one['payment_id'])->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::find($two['payment_id'])->status);
        // Одно агрегированное событие со СПИСКОМ ids, а не два отдельных.
        Log::shouldHaveReceived('warning')
            ->with(self::ESCALATION_MSG, \Mockery::on(
                fn ($ctx) => count($ctx['ids']) === 2
                    && in_array($one['payment_id'], $ctx['ids'], true)
                    && in_array($two['payment_id'], $ctx['ids'], true)
            ))
            ->once();
    }

    public function testThresholdZeroDisablesEscalationButKeepsCounters(): void
    {
        // Порог 0 = фича выключена: счётчики пишутся, эскалации нет (без сравнений).
        config(['calculator.payment_poll_error_threshold' => 0]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3070, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);

        Log::spy();
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $this->poll()['escalated']);
        }

        $this->assertSame(3, Payment::find($ctx['payment_id'])->poll_error_streak);
        Log::shouldNotHaveReceived('warning', [self::ESCALATION_MSG, \Mockery::type('array')]);
    }

    // --- admin recheck через тот же write-path ---

    public function testAdminRecheckSuccessResetsStreak(): void
    {
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(3080, name: 'Owner');
        $this->grantRole(3080, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);
        Payment::where('id', $ctx['payment_id'])->update(['poll_error_streak' => 7, 'last_poll_result' => 'error']);

        // Перевода нет, но опрос УСПЕШЕН ('pending') → маркер снимается, streak → 0.
        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.poll', 'pending');

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(0, $payment->poll_error_streak);
        $this->assertSame('pending', $payment->last_poll_result);
        $this->assertNotNull($payment->last_polled_at);
    }

    public function testAdminRecheckErrorIncrementsStreakAndKeepsStatus(): void
    {
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(3090, name: 'Owner');
        $this->grantRole(3090, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);
        Payment::where('id', $ctx['payment_id'])->update(['status' => Payment::STATUS_EXPIRED, 'poll_error_streak' => 2]);
        FakeTonPayGateway::failFor($ctx['memo']);

        $this->postJson("/api/v1/admin/payments/{$ctx['payment_id']}/recheck", [], $this->adminHeaders($ownerData))
            ->assertOk()
            ->assertJsonPath('data.payment_status', Payment::STATUS_EXPIRED)
            ->assertJsonPath('data.poll', 'error');

        $payment = Payment::find($ctx['payment_id']);
        $this->assertSame(Payment::STATUS_EXPIRED, $payment->status); // B4: статус не тронут
        $this->assertSame(3, $payment->poll_error_streak);
        $this->assertSame('error', $payment->last_poll_result);
    }

    // --- admin API: поля + фильтр ---

    public function testAdminPaymentsExposesPollFieldsAndProblemFilter(): void
    {
        config(['calculator.payment_poll_error_threshold' => 5]);
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(3100, name: 'Owner');
        $this->grantRole(3100, 'owner');
        $problem = $this->payOrder($ownerData, $p->id);
        Payment::where('id', $problem['payment_id'])->update([
            'poll_error_streak' => 6, 'last_poll_result' => 'error', 'last_polled_at' => now(),
        ]);
        // Второй платёж (здоровый) — topup, чтобы не упереться в один-живой-инвойс на заказ.
        $healthy = $this->postJson('/api/v1/cabinet/wallet/topup', ['amount_cents' => 5000], $this->tgHeaders($ownerData))
            ->assertOk()->json('data');

        $all = $this->getJson('/api/v1/admin/payments', $this->adminHeaders($ownerData))
            ->assertOk()->json('data.data');
        $byId = collect($all)->keyBy('id');
        $this->assertSame('error', $byId[$problem['payment_id']]['last_poll_result']);
        $this->assertSame(6, $byId[$problem['payment_id']]['poll_error_streak']);
        $this->assertTrue($byId[$problem['payment_id']]['poll_problem']);
        $this->assertNotNull($byId[$problem['payment_id']]['last_polled_at']);
        $this->assertFalse($byId[$healthy['payment_id']]['poll_problem']);
        $this->assertSame(0, $byId[$healthy['payment_id']]['poll_error_streak']);

        // Фильтр «проблемный опрос» отдаёт только платежи со streak >= порога.
        $filtered = $this->getJson('/api/v1/admin/payments?poll_problem=1', $this->adminHeaders($ownerData))
            ->assertOk()->json('data.data');
        $this->assertSame([$problem['payment_id']], array_column($filtered, 'id'));

        // [auth negative] Без токена — 401.
        $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
    }

    public function testProblemFilterMatchesNothingWhenThresholdDisabled(): void
    {
        // Порог 0: poll_problem всегда false, фильтр не матчит ничего (семантика
        // зафиксирована — консистентно с маркером).
        config(['calculator.payment_poll_error_threshold' => 0]);
        $p = $this->makeProduct();
        [$ownerData] = $this->registerTg(3110, name: 'Owner');
        $this->grantRole(3110, 'owner');
        $ctx = $this->payOrder($ownerData, $p->id);
        Payment::where('id', $ctx['payment_id'])->update(['poll_error_streak' => 99, 'last_poll_result' => 'error']);

        $all = $this->getJson('/api/v1/admin/payments', $this->adminHeaders($ownerData))
            ->assertOk()->json('data.data');
        $this->assertFalse(collect($all)->keyBy('id')[$ctx['payment_id']]['poll_problem']);

        $filtered = $this->getJson('/api/v1/admin/payments?poll_problem=1', $this->adminHeaders($ownerData))
            ->assertOk()->json('data.data');
        $this->assertSame([], $filtered);
    }

    public function testSupportSeesPaymentsListWithPollFields(): void
    {
        // Существующий RBAC списка не менялся: support видит платежи (recheck ему запрещён —
        // testAdminRecheckDeniedForSupport в PaymentHardeningTest).
        $p = $this->makeProduct();
        [$ownerData, $ownerRef] = $this->registerTg(3120, name: 'Owner');
        $this->grantRole(3120, 'owner');
        [$supportData] = $this->registerTg(3121, $ownerRef, 'Support');
        $this->grantRole(3121, 'support');
        $this->payOrder($ownerData, $p->id);

        $rows = $this->getJson('/api/v1/admin/payments', $this->adminHeaders($supportData))
            ->assertOk()->json('data.data');
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('poll_problem', $rows[0]);
        $this->assertArrayHasKey('last_poll_result', $rows[0]);
    }

    // --- команда: summary ---

    public function testCommandReportsEscalatedInSummary(): void
    {
        config(['calculator.payment_poll_error_threshold' => 1]);
        $p = $this->makeProduct();
        [$data] = $this->registerTg(3130, name: 'A');
        $ctx = $this->payOrder($data, $p->id);
        FakeTonPayGateway::failFor($ctx['memo']);

        $this->artisan('commerce:tonpay-poll')
            ->expectsOutputToContain('escalated=1')
            ->assertExitCode(0);
    }
}
