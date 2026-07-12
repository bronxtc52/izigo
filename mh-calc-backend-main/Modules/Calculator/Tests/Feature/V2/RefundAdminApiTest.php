<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\OrderService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\PeriodCorrection;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use RuntimeException;
use Tests\TestCase;

/**
 * T12 [ПРАВА / ВАЛИДАЦИЯ / ЗАКРЫТЫЕ ПЕРИОДЫ]: admin-API возвратов.
 * Deny-by-default (флаг OFF => 403 даже owner); create/approve/post — owner-only,
 * read — owner,finance; 422 (неоплачен/qty>ordered/повторный полный); 409 (approve
 * уже проведённой корректировки); setStatus(refunded) напрямую при флаге ON —
 * запрещён; закрытый период — только proposed-корректировка (post после approve).
 */
class RefundAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $secret = 'whsec_t12api';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
        $this->activatePolicy();
    }

    private function activatePolicy(): void
    {
        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-t12-api', DefaultPolicyConfig::doc(), null);
        $service->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
    }

    private function product(int $priceCents = 9000, int $pv = 90, ?int $bvCents = null): Product
    {
        return Product::query()->create([
            'name' => 'Tariff', 'price_usdt_cents' => $priceCents, 'pv' => $pv,
            'bv_usd_cents' => $bvCents, 'package_id' => 1, 'sku' => 'TARIFF-API-' . uniqid(),
            'is_active' => true, 'sort' => 1,
        ]);
    }

    private function buyAndPay(string $data, int $productId, int $qty = 1): int
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId, 'qty' => $qty], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();

        return $orderId;
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function owner(): array
    {
        [$data] = $this->registerTg(900, name: 'Owner');
        $this->grantRole(900, 'owner');

        return $this->adminHeaders($data);
    }

    // ------------------------------------------------------------------ RBAC / flag

    public function testCreateRequiresWebAuthNotInitData(): void
    {
        $this->enableFeatureFlags('mh_v2_refunds');
        [$data] = $this->registerTg(901, name: 'X');
        $this->postJson('/api/v1/admin/v2/refunds', ['order_id' => 1, 'kind' => 'full', 'reason' => 'x'],
            $this->tgHeaders($data))->assertStatus(401);
    }

    public function testFlagOffBlocksEvenOwner(): void
    {
        $headers = $this->owner(); // флаг НЕ включён
        $this->getJson('/api/v1/admin/v2/refunds', $headers)
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testNonOwnerCannotCreateButFinanceCanRead(): void
    {
        $this->enableFeatureFlags('mh_v2_refunds');
        [$fData] = $this->registerTg(902, name: 'Fin');
        $this->grantRole(902, 'finance');
        $fin = $this->adminHeaders($fData);

        // finance читает список.
        $this->getJson('/api/v1/admin/v2/refunds', $fin)->assertOk()->assertJsonPath('status', 'success');
        // finance НЕ может создать возврат (owner-only).
        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => 1, 'kind' => 'full', 'reason' => 'x'], $fin)->assertStatus(403);
    }

    public function testNoRoleGets403OnList(): void
    {
        $this->enableFeatureFlags('mh_v2_refunds');
        [$xData] = $this->registerTg(903, name: 'X');
        $this->getJson('/api/v1/admin/v2/refunds', $this->adminHeaders($xData))->assertStatus(403);
    }

    // ------------------------------------------------------------------ validation (422)

    public function testUnpaidOrderIsRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(904, name: 'B');
        // Заказ создан, но НЕ оплачен.
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $this->product()->id], $this->tgHeaders($bData))->json('data.id');

        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $orderId, 'kind' => 'full', 'reason' => 'x'], $headers)->assertStatus(422);
    }

    public function testQtyGreaterThanOrderedIsRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(905, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id, qty: 1);
        $itemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        $this->postJson('/api/v1/admin/v2/refunds', [
            'order_id' => $orderId, 'kind' => 'partial', 'reason' => 'x',
            'lines' => [['order_item_id' => $itemId, 'qty' => 5]],
        ], $headers)->assertStatus(422);
    }

    public function testSecondFullReturnIsRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(906, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id);

        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $orderId, 'kind' => 'full', 'reason' => 'first', 'idempotency_key' => 'r1'], $headers)
            ->assertStatus(201);
        // Заказ уже refunded → повторный полный возврат отклонён (другой ключ).
        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $orderId, 'kind' => 'full', 'reason' => 'second', 'idempotency_key' => 'r2'], $headers)
            ->assertStatus(422);
    }

    public function testCreateHappyPathReturns201(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral', 'mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(907, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id);

        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $orderId, 'kind' => 'full', 'reason' => 'ok'], $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', OrderReturn::STATUS_REVERSED)
            ->assertJsonPath('data.kind', 'full');
    }

    // ------------------------------------------------------------------ setStatus guard

    public function testDirectRefundViaSetStatusBlockedWhenFlagOn(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        [$bData] = $this->registerTg(908, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id);

        $this->expectException(RuntimeException::class);
        app(OrderService::class)->setStatus($orderId, Order::STATUS_REFUNDED);
    }

    public function testDirectRefundViaSetStatusAllowedWhenFlagOff(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes'); // mh_v2_refunds OFF
        [$bData] = $this->registerTg(909, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id);

        $res = app(OrderService::class)->setStatus($orderId, Order::STATUS_REFUNDED);
        $this->assertSame(Order::STATUS_REFUNDED, $res['status']); // прежнее V1-поведение сохранено
    }

    // ------------------------------------------------------------------ закрытые периоды

    /** Построить возврат, задевающий сматченный лот + posted-структурную в ЗАКРЫТОМ периоде. */
    private function buildClosedPeriodStructural(): array
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $bronze = $this->product(9000, 90, 9000);
        [, $rootRef] = $this->registerTg(910, name: 'Root');
        [$aData] = $this->registerTg(911, $rootRef, 'A');
        [$bData] = $this->registerTg(912, $rootRef, 'B');
        $orderA = $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $rootId = $this->memberByTg(910)->id;

        // Матчинг: лот заказа A потреблён.
        $match = app(BinaryMatchingService::class)
            ->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');

        // ЗАКРЫТЫЙ период + posted-структурная строка, привязанная к матчу.
        $periodId = DB::table('v2_calc_periods')->insertGetId([
            'period_type' => 'half_month', 'code' => '2026-07-H1',
            'starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-07-16 00:00:00',
            'timezone' => 'UTC', 'status' => 'closed', 'policy_version_id' => 1,
            'closed_at' => now(), 'closed_by' => 'system', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $struct = StructureBonus::query()->create([
            'period_id' => $periodId, 'member_id' => $rootId, 'policy_version_id' => 1,
            'rank_code' => 'CONSULTANT', 'rate_bps' => 500, 'matched_pv' => '90',
            'matched_bv_cents' => 9000, 'match_group_id' => $match->id, 'gross_cents' => 450,
            'half_cap_cents' => 50000, 'monthly_cap_cents' => 50000, 'cap_remaining_before_cents' => 50000,
            'after_cap_cents' => 450, 'net_cents' => 450, 'accrual_month' => '2026-07',
            'status' => StructureBonus::STATUS_POSTED,
            'posting_idempotency_key' => "v2:structure:{$periodId}:{$rootId}",
        ]);

        return ['orderA' => $orderA, 'match' => $match, 'struct' => $struct, 'root' => $rootId];
    }

    public function testClosedPeriodProposesCorrectionAndNeedsManual(): void
    {
        $ctx = $this->buildClosedPeriodStructural();
        $headers = $this->owner();

        $resp = $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $ctx['orderA'], 'kind' => 'full', 'reason' => 'закрытый период'], $headers)
            ->assertStatus(201);
        // Каскад по закрытому периоду → возврат требует ручного утверждения.
        $resp->assertJsonPath('data.status', OrderReturn::STATUS_NEEDS_MANUAL);

        // Прямой проводки НЕТ — только proposed-корректировка структурной.
        $corr = PeriodCorrection::query()->where('bonus_type', 'structural')->sole();
        $this->assertSame(PeriodCorrection::STATUS_PROPOSED, $corr->status);
        $this->assertLessThan(0, $corr->amount_cents); // сторно (отрицательное)
        $this->assertNull($corr->ledger_tx_id);

        // Матч помечен на каскадный reversal (T03).
        $this->assertNotNull(BinaryMatch::query()->find($ctx['match']->id)->reversal_required_at);
    }

    public function testCorrectionApprovePostFlowAndDoublePostConflict(): void
    {
        $ctx = $this->buildClosedPeriodStructural();
        $headers = $this->owner();
        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $ctx['orderA'], 'kind' => 'full', 'reason' => 'r'], $headers)->assertStatus(201);
        $corr = PeriodCorrection::query()->where('bonus_type', 'structural')->sole();

        // Пост до approve → 409.
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/post", [], $headers)->assertStatus(409);

        // approve → post.
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/approve", [], $headers)
            ->assertOk()->assertJsonPath('data.status', PeriodCorrection::STATUS_APPROVED);
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/post", [], $headers)
            ->assertOk()->assertJsonPath('data.status', PeriodCorrection::STATUS_POSTED);

        // Повторный post уже проведённой → 409.
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/post", [], $headers)->assertStatus(409);
        // Повторный approve уже проведённой → 409.
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/approve", [], $headers)->assertStatus(409);
    }

    public function testCorrectionListReadableByFinanceOwnerOnlyMutates(): void
    {
        $ctx = $this->buildClosedPeriodStructural();
        $headers = $this->owner();
        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $ctx['orderA'], 'kind' => 'full', 'reason' => 'r'], $headers)->assertStatus(201);
        $corr = PeriodCorrection::query()->where('bonus_type', 'structural')->sole();

        [$fData] = $this->registerTg(913, name: 'Fin');
        $this->grantRole(913, 'finance');
        $fin = $this->adminHeaders($fData);

        // finance читает очередь.
        $this->getJson('/api/v1/admin/v2/period-corrections?status=proposed', $fin)
            ->assertOk()->assertJsonPath('data.0.id', $corr->id);
        // finance НЕ утверждает (owner-only).
        $this->postJson("/api/v1/admin/v2/period-corrections/{$corr->id}/approve", [], $fin)->assertStatus(403);
    }

    // ------------------------------------------------------------------ MF-W5-1: открытый период

    /** Возврат, задевающий сматченный лот + posted-структурную в ОТКРЫТОМ (незакрытом) периоде на НС. */
    private function buildOpenPeriodStructural(): array
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $bronze = $this->product(9000, 90, 9000);
        [, $rootRef] = $this->registerTg(920, name: 'Root');
        [$aData] = $this->registerTg(921, $rootRef, 'A');
        [$bData] = $this->registerTg(922, $rootRef, 'B');
        $orderA = $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $rootId = $this->memberByTg(920)->id;

        $match = app(BinaryMatchingService::class)
            ->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-open-1');

        // ОТКРЫТЫЙ half-month период + posted-структурная (ещё на НС, не переведена на ОС).
        $periodId = DB::table('v2_calc_periods')->insertGetId([
            'period_type' => 'half_month', 'code' => '2026-07-H1-open',
            'starts_at' => '2026-07-01 00:00:00', 'ends_at' => '2026-07-16 00:00:00',
            'timezone' => 'UTC', 'status' => 'open', 'policy_version_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $struct = StructureBonus::query()->create([
            'period_id' => $periodId, 'member_id' => $rootId, 'policy_version_id' => 1,
            'rank_code' => 'CONSULTANT', 'rate_bps' => 500, 'matched_pv' => '90',
            'matched_bv_cents' => 9000, 'match_group_id' => $match->id, 'gross_cents' => 450,
            'half_cap_cents' => 50000, 'monthly_cap_cents' => 50000, 'cap_remaining_before_cents' => 50000,
            'after_cap_cents' => 450, 'net_cents' => 450, 'accrual_month' => '2026-07',
            'status' => StructureBonus::STATUS_POSTED,
            'posting_idempotency_key' => "v2:structure:{$periodId}:{$rootId}",
        ]);

        // T06 при закрытии half-month закредитовал бы НС сырым after_cap — симулируем.
        app(WalletAccountsV2Service::class)->credit(
            $rootId, 'ns', 450, "v2:structure_ns:{$periodId}:{$rootId}",
            sourceType: 'structure_bonus', sourceId: $struct->id, accrualMonth: '2026-07',
        );

        return ['orderA' => $orderA, 'match' => $match, 'struct' => $struct, 'root' => $rootId];
    }

    public function testOpenPeriodStructuralReversesOnNsWithoutCorrection(): void
    {
        $ctx = $this->buildOpenPeriodStructural();
        $headers = $this->owner();
        $rootId = $ctx['root'];

        $this->assertSame(450, (int) DB::table('v2_member_accounts')->where('member_id', $rootId)->value('ns_cents'));

        $resp = $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $ctx['orderA'], 'kind' => 'full', 'reason' => 'открытый период'], $headers)
            ->assertStatus(201);
        // Открытый период → авто-сторно на НС, БЕЗ ручного утверждения.
        $resp->assertJsonPath('data.status', OrderReturn::STATUS_REVERSED);

        // НС уменьшен на сторнированную структурную (450 → 0). OS-корректировка НЕ создана.
        $this->assertSame(0, (int) DB::table('v2_member_accounts')->where('member_id', $rootId)->value('ns_cents'));
        $this->assertSame(0, PeriodCorrection::query()->where('bonus_type', 'structural')->count());

        // Провенанс: bonus_reversal структурной на НС (amount −450).
        $return = OrderReturn::query()->where('order_id', $ctx['orderA'])->sole();
        $act = ReversalAction::query()->where('return_id', $return->id)
            ->where('action_type', ReversalAction::TYPE_BONUS_REVERSAL)
            ->where('bonus_type', ReversalAction::BONUS_STRUCTURAL)->sole();
        $this->assertSame(-450, (int) $act->amount_cents);
        $this->assertSame('ns_within_month_reversal', $act->snapshot_json['basis']);

        // Перевод НС→ОС июля НЕ переносит сторнированное (нетто 0).
        app(WalletAccountsV2Service::class)->executeForCalibratedMonth('2026-07', 10000);
        $this->assertSame(0, (int) DB::table('v2_member_accounts')->where('member_id', $rootId)->value('os_available_cents'));
    }

    // ------------------------------------------------------------------ MF-W5-2/3: частичные возвраты

    public function testPartialReturnsCumulativeCoverageMarkRefundedOnlyWhenFull(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(930, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product(9000, 90, 9000)->id, qty: 2);
        $itemId = (int) DB::table('order_items')->where('order_id', $orderId)->value('id');

        // Первый частичный (1 из 2) — заказ остаётся paid, повторный возврат остатка возможен.
        $this->postJson('/api/v1/admin/v2/refunds', [
            'order_id' => $orderId, 'kind' => 'partial', 'reason' => 'часть 1',
            'idempotency_key' => 'p1', 'lines' => [['order_item_id' => $itemId, 'qty' => 1]],
        ], $headers)->assertStatus(201)->assertJsonPath('data.status', OrderReturn::STATUS_REVERSED);
        $this->assertSame(Order::STATUS_PAID, Order::find($orderId)->status);

        // Второй частичный (остаток) — НЕ 422; полное покрытие → заказ refunded.
        $this->postJson('/api/v1/admin/v2/refunds', [
            'order_id' => $orderId, 'kind' => 'partial', 'reason' => 'часть 2',
            'idempotency_key' => 'p2', 'lines' => [['order_item_id' => $itemId, 'qty' => 1]],
        ], $headers)->assertStatus(201);
        $this->assertSame(Order::STATUS_REFUNDED, Order::find($orderId)->status);
    }

    public function testPartialWithoutLinesRejectedWithClearMessage(): void
    {
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_refunds');
        $headers = $this->owner();
        [$bData] = $this->registerTg(931, name: 'B');
        $orderId = $this->buyAndPay($bData, $this->product()->id);

        $this->postJson('/api/v1/admin/v2/refunds',
            ['order_id' => $orderId, 'kind' => 'partial', 'reason' => 'без строк'], $headers)
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Частичный возврат требует непустой список позиций (lines[])']);
    }
}
