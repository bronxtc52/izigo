<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\LeadService;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Services\PaymentService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B2 (P2-hardening): сериализация гонки лид-экспирации vs оплата (TOCTOU). Прежний expireDue
 * читал stale-snapshot «занятых» лидов, а параллельная оплата в окне создавала платёж/промоутила
 * лида — удаление осиротило заказ/платёж (FK nullOnDelete) → markPaid «нет участника» → деньги
 * без фулфилмента. Фикс: атомарный условный DELETE (коррелированный NOT EXISTS) + общий
 * lead-lifecycle advisory-lock на expireDue / attachOrReattach / promote / markPaid, порядок
 * строго lead-lifecycle → activation.
 *
 * Настоящую параллельность в PHPUnit не смоделировать — по образцу ActivationLockTest доказываем,
 * что лок реально берётся на всех путях (вторым DB-коннектом, держащим лок), плюс атомарную
 * семантику удаления и отсутствие само-дедлока при включённом V2-движке.
 */
class LeadLifecycleLockTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    /**
     * Литерал lead-lifecycle-лока для второго коннекта. Намеренно НЕ через
     * LeadService::LEAD_LIFECYCLE_LOCK_KEY, чтобы тесты дисциплины лока запускались и на коде
     * БЕЗ фикса (там константы нет) и падали по ПОВЕДЕНИЮ (лок не берётся → нет таймаута), а не
     * по «undefined constant». testLockKeyConstantMatchesLiteral ловит дрейф значения.
     */
    private const LOCK_KEY = 0x12916002;

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

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    /** Второй коннект (отдельная сессия Postgres), держащий lead-lifecycle advisory-lock. */
    private function holdLeadLockOnSecondConnection(): void
    {
        config(['database.connections.pgsql_lock_holder' => config('database.connections.' . config('database.default'))]);
        DB::connection('pgsql_lock_holder')->beginTransaction();
        DB::connection('pgsql_lock_holder')
            ->statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    private function releaseLock(): void
    {
        DB::connection('pgsql_lock_holder')->rollBack();
        DB::purge('pgsql_lock_holder');
    }

    private function makePayment(int $leadId, ?int $orderId, string $status, string $ref): Payment
    {
        return Payment::query()->create([
            'order_id' => $orderId,
            'member_id' => null,
            'lead_id' => $leadId,
            'provider' => 'ton_pay',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => 9000,
            'currency' => 'USDT',
            'status' => $status,
            'external_ref' => $ref,
        ]);
    }

    // ── Дисциплина локов: лок реально берётся (RED на старом коде без лока) ──

    public function testLockKeyConstantMatchesLiteralAndIsDistinct(): void
    {
        // Значение lead-lifecycle-лока фиксировано и ОТЛИЧНО от activation-лока (иначе паразитная
        // сериализация/дедлок). Ловит дрейф, из-за которого второй коннект держал бы «не тот» лок.
        $this->assertSame(self::LOCK_KEY, LeadService::LEAD_LIFECYCLE_LOCK_KEY);
        $this->assertNotSame(
            \Modules\Calculator\Services\ActivationService::ACTIVATION_LOCK_KEY,
            LeadService::LEAD_LIFECYCLE_LOCK_KEY
        );
    }

    public function testExpireDueBlocksOnHeldLeadLifecycleLock(): void
    {
        [, $ref] = $this->registerTg(400);
        $this->makeLead(401, $ref);
        Lead::where('telegram_id', 401)->update(['expires_at' => now()->subDay()]);

        $this->holdLeadLockOnSecondConnection();

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/lock timeout|canceling statement/i');

            DB::transaction(function () {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                app(LeadService::class)->expireDue();
            });
        } finally {
            $this->releaseLock();
        }
    }

    public function testMarkPaidTakesLeadLifecycleLockForLeadOrder(): void
    {
        [, $ref] = $this->registerTg(410);
        [$leadInit] = $this->makeLead(411, $ref);
        $lead = Lead::where('telegram_id', 411)->firstOrFail();
        $product = $this->bronze();

        $orderId = app(OrderService::class)->createForLead($lead, $product->id)['id'];
        $payment = $this->makePayment($lead->id, $orderId, Payment::STATUS_PENDING, 'pay:7001');

        $this->holdLeadLockOnSecondConnection();

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/lock timeout|canceling statement/i');

            DB::transaction(function () use ($payment) {
                DB::statement("SET LOCAL lock_timeout = '250ms'");
                app(PaymentService::class)->confirmPayment($payment->id);
            });
        } finally {
            $this->releaseLock();
        }

        unset($leadInit);
    }

    // ── Атомарная семантика удаления (не сломать защиту лидов с незавершённым платежом) ──

    public function testExpireDueKeepsExpiredLeadWithCreatedPayment(): void
    {
        // created — тоже незавершённый (инвойс выдан, деньги могут прийти): лид не удаляем.
        [, $ref] = $this->registerTg(420);
        $this->makeLead(421, $ref); // с created-платежом → keep
        $this->makeLead(422, $ref); // без платежа → delete (контроль)
        Lead::whereIn('telegram_id', [421, 422])->update(['expires_at' => now()->subDay()]);
        $this->makePayment(Lead::where('telegram_id', 421)->value('id'), null, Payment::STATUS_CREATED, 'pay:8001');

        $removed = app(LeadService::class)->expireDue();

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('leads', ['telegram_id' => 421]);
        $this->assertDatabaseMissing('leads', ['telegram_id' => 422]);
    }

    public function testAttachReattachKeepsExpiredLeadWithPendingPayment(): void
    {
        // Ветка удаления истёкшего в attachOrReattach: под локом перепроверяет платёж — лид с
        // незавершённым платежом НЕ открепляется (иначе FK осиротит платёж в полёте чекаута).
        [, $refA] = $this->registerTg(430);
        [, $refB] = $this->registerTg(530);
        $this->makeLead(431, $refA);
        Lead::where('telegram_id', 431)->update(['expires_at' => now()->subDay()]);
        $leadId = Lead::where('telegram_id', 431)->value('id');
        $this->makePayment($leadId, null, Payment::STATUS_PENDING, 'pay:8100');

        // Повторный заход по рефке B: истёкший лид защищён pending-платежом — тот же лид, спонсор НЕ сменён.
        $result = app(LeadService::class)->attachOrReattach(431, 'U431', 'u431', $refB, null);

        $this->assertNotNull($result);
        $this->assertSame($leadId, $result->id);
        $this->assertSame(Member::where('telegram_id', 430)->value('id'), $result->sponsor_id);
        $this->assertDatabaseHas('leads', ['id' => $leadId]);
    }

    // ── Happy promote: все заказы И платежи лида переносятся на участника (не осиротеть) ──

    public function testPromoteReparentsAllOrdersAndPaymentsOfLead(): void
    {
        $bronze = $this->bronze();
        [, $ref] = $this->registerTg(440, name: 'Root');
        [$leadInit] = $this->makeLead(441, $ref);

        $o1 = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))->json('data.id');
        $o2 = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))->json('data.id');
        $p1 = $this->postJson("/api/v1/cabinet/orders/{$o1}/pay", [], $this->tgHeaders($leadInit))->json('data');
        $p2 = $this->postJson("/api/v1/cabinet/orders/{$o2}/pay", [], $this->tgHeaders($leadInit))->json('data');
        FakeTonPayGateway::fakePay($p1['memo'], $p1['amount_cents']);

        // Оплата первого промоутит лида; второй заказ И его платёж должны быть перепривязаны.
        $this->postJson("/api/v1/cabinet/payments/{$p1['payment_id']}/check", [], $this->tgHeaders($leadInit))->assertOk();

        $member = Member::where('telegram_id', 441)->firstOrFail();
        $this->assertDatabaseHas('orders', ['id' => $o2, 'member_id' => $member->id, 'lead_id' => null]);
        $this->assertDatabaseHas('payments', ['id' => $p2['payment_id'], 'member_id' => $member->id, 'lead_id' => null]);
        // Ни один заказ/платёж лида не осиротел (lead_id обнулён с проставленным member_id).
        $this->assertSame(0, Order::query()->whereNull('member_id')->whereNull('lead_id')->count());
        $this->assertSame(0, Payment::query()->whereNull('member_id')->whereNull('lead_id')->count());
    }

    // ── Порядок локов lead-lifecycle → activation: нет само-дедлока при V2-движке ON ──

    public function testLeadPurchaseCompletesWithV2EngineFlagNoDeadlock(): void
    {
        // markPaid берёт lead-lifecycle-лок (промоушн), затем activation-лок (capture V2 + activate).
        // Единый порядок без цикла — покупка лида доходит до paid без дедлока/ошибки.
        $this->enableFeatureFlags('mh_plan_v2_engine');
        $bronze = $this->bronze();
        [, $ref] = $this->registerTg(450, name: 'Root');
        [$leadInit] = $this->makeLead(451, $ref);

        $orderId = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($leadInit))->json('data');
        FakeTonPayGateway::fakePay($pay['memo'], $pay['amount_cents']);

        $this->postJson("/api/v1/cabinet/payments/{$pay['payment_id']}/check", [], $this->tgHeaders($leadInit))
            ->assertOk()->assertJsonPath('data.payment_status', Payment::STATUS_PAID);

        $this->assertSame(Order::STATUS_PAID, Order::find($orderId)->status);
        $this->assertNotNull(Member::where('telegram_id', 451)->first());
        $this->assertDatabaseMissing('leads', ['telegram_id' => 451]);
    }
}
