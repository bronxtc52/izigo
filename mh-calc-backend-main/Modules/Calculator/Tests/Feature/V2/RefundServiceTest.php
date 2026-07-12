<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Models\ReferralReward;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Modules\Calculator\V2\Services\Refunds\RefundService;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * T12 [ДЕНЬГИ]: сторно бонусов при возврате. Реферальная сторнируется НЕМЕДЛЕННО по
 * ORIGINAL rate/tier снапшоту (CAL-REV-001) с обратным знаком; частичный возврат —
 * пропорция строго по снапшоту (rounding-инвариант); нехватка ОС → clawback-долг;
 * ранг/награда/тир — навсегда (DEC-020/027/010); PV-лоты реверсятся (T03).
 * Идемпотентность по idempotency_key; оркестратор под advisory-lock ACTIVATION_LOCK.
 */
class RefundServiceTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $secret = 'whsec_t12';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral', 'mh_v2_refunds');
    }

    // ------------------------------------------------------------------ helpers

    private function activatePolicy(): void
    {
        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-t12-test', DefaultPolicyConfig::doc(), null);
        $service->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
    }

    private function product(int $priceCents = 9000, int $pv = 90, ?int $bvCents = null): Product
    {
        return Product::query()->create([
            'name' => 'Tariff', 'price_usdt_cents' => $priceCents, 'pv' => $pv,
            'bv_usd_cents' => $bvCents, 'package_id' => 1, 'sku' => 'TARIFF-T12-' . uniqid(),
            'is_active' => true, 'sort' => 1,
        ]);
    }

    /** Реф-цепочка A ← B ← C (sponsor_id). */
    private function chain(): array
    {
        [$aData, $aRef] = $this->registerTg(700, name: 'A');
        [$bData, $bRef] = $this->registerTg(701, $aRef, 'B');
        [$cData] = $this->registerTg(702, $bRef, 'C');

        return [
            'a' => $this->memberByTg(700)->id,
            'b' => $this->memberByTg(701)->id,
            'c' => $this->memberByTg(702)->id,
            'aData' => $aData, 'bData' => $bData, 'cData' => $cData,
        ];
    }

    private function seedTier(int $memberId, string $tier): void
    {
        DB::table('v2_tier_history')->insertOrIgnore([
            'member_id' => $memberId, 'tier' => $tier, 'tier_before' => null,
            'basis_personal_pv' => '0', 'source_order_id' => null, 'policy_version_id' => 1,
            'effective_at' => CarbonImmutable::parse('2026-02-01', 'UTC'), 'created_at' => now(),
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

    private function osBalance(int $memberId): int
    {
        return (int) (MemberAccountV2::query()->where('member_id', $memberId)->value('os_available_cents') ?? 0);
    }

    private function refunds(): RefundService
    {
        return app(RefundService::class);
    }

    private function assertLedgerBalancedByTx(?string $txId): void
    {
        $this->assertNotNull($txId);
        $row = DB::table('ledger_entries')->where('tx_id', $txId)
            ->selectRaw("SUM(CASE WHEN direction='debit' THEN amount_cents ELSE 0 END) AS dr")
            ->selectRaw("SUM(CASE WHEN direction='credit' THEN amount_cents ELSE 0 END) AS cr")
            ->first();
        $this->assertSame((int) $row->dr, (int) $row->cr, 'reversal tx не сбалансирован');
        $this->assertGreaterThan(0, (int) $row->dr);
    }

    // ------------------------------------------------------------------ money

    public function testFullReturnReversesReferralByOriginalSnapshot(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');    // L1 всегда 10%
        $this->seedTier($ids['a'], 'BUSINESS'); // L2 5%
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90, 9000)->id);

        $this->assertSame(900, $this->osBalance($ids['b']));
        $this->assertSame(450, $this->osBalance($ids['a']));

        $return = $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'клиент вернул', null, 'k-full-1');

        $this->assertSame(OrderReturn::STATUS_REVERSED, $return->status);
        // ОС сторнирован ровно на начисленное (обратный знак исходной проводки).
        $this->assertSame(0, $this->osBalance($ids['b']));
        $this->assertSame(0, $this->osBalance($ids['a']));

        // Награды помечены сторнированными.
        foreach ([$ids['b'], $ids['a']] as $mid) {
            $r = ReferralReward::query()->where('order_id', $orderId)
                ->where('beneficiary_member_id', $mid)->sole();
            $this->assertNotNull($r->reversed_at);
            $this->assertSame('клиент вернул', $r->reversal_reason);
        }

        // Reversal-action по ORIGINAL снапшоту (rate/tier), суммы = -gross.
        $act = ReversalAction::query()->where('return_id', $return->id)
            ->where('bonus_type', ReversalAction::BONUS_REFERRAL)
            ->where('target_id', ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->value('id'))
            ->sole();
        $this->assertSame(-900, $act->amount_cents);
        $this->assertSame(1000, $act->snapshot_json['rate_bps']);
        $this->assertSame('ELITE', $act->snapshot_json['tier_snapshot']);
        $this->assertLedgerBalancedByTx($act->ledger_tx_id);

        // Заказ переведён в refunded (возврат денег покупателю — вне системы).
        $this->assertSame(Order::STATUS_REFUNDED, Order::find($orderId)->status);
    }

    public function testReturnIsIdempotentByKey(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90, 9000)->id);
        $this->assertSame(900, $this->osBalance($ids['b']));

        $first = $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'r', null, 'same-key');
        $second = $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'r', null, 'same-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OrderReturn::query()->where('order_id', $orderId)->count());
        // Сторно применилось РОВНО один раз (не задвоилось в минус).
        $this->assertSame(0, $this->osBalance($ids['b']));
        $this->assertSame(1, ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)
            ->whereNotNull('reversed_at')->count());
    }

    public function testPartialReturnReversesProportionBySnapshot(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        // qty=2, BV 10000/ед → база 20000, L1 = 10% = 2000.
        $orderId = $this->buyAndPay($ids['cData'], $this->product(10000, 100, 10000)->id, qty: 2);
        $this->assertSame(2000, $this->osBalance($ids['b']));

        $itemId = DB::table('order_items')->where('order_id', $orderId)->value('id');
        $return = $this->refunds()->create(
            $orderId, OrderReturn::KIND_PARTIAL,
            [['order_item_id' => (int) $itemId, 'qty' => 1]], 'частичный', null, 'k-part-1',
        );

        $this->assertSame(OrderReturn::STATUS_REVERSED, $return->status);
        $this->assertSame(10000, $return->returned_bv_cents); // половина базы
        // Сторно пропорционально: 2000 * 10000/20000 = 1000.
        $this->assertSame(1000, $this->osBalance($ids['b']));
        // Частичный возврат НЕ закрывает награду целиком.
        $this->assertNull(ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->value('reversed_at'));
        $act = ReversalAction::query()->where('return_id', $return->id)
            ->where('bonus_type', ReversalAction::BONUS_REFERRAL)->sole();
        $this->assertSame(-1000, $act->amount_cents);
    }

    public function testInsufficientOsFallsToClawbackDebt(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90, 9000)->id);
        $this->assertSame(900, $this->osBalance($ids['b']));

        // B потратил всю премию (ОС=0) до возврата.
        app(WalletAccountsV2Service::class)->debit($ids['b'], LedgerV2::SUBACCOUNT_OS, 900, 'spend-b');
        $this->assertSame(0, $this->osBalance($ids['b']));

        $return = $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'возврат', null, 'k-claw');

        $this->assertSame(OrderReturn::STATUS_REVERSED, $return->status);
        $this->assertSame(0, $this->osBalance($ids['b'])); // ОС не уходит в минус
        // Недостача ушла в clawback-долг.
        $claw = ReversalAction::query()->where('return_id', $return->id)
            ->where('action_type', ReversalAction::TYPE_CLAWBACK)->sole();
        $this->assertSame(-900, $claw->amount_cents);
        $this->assertLedgerBalancedByTx($claw->ledger_tx_id);
        $debt = (int) DB::table('ledger_entries')
            ->where('member_id', $ids['b'])->where('account_type', 'member_clawback_debt')
            ->where('direction', 'debit')->sum('amount_cents');
        $this->assertSame(900, $debt);
    }

    public function testRankAwardTierNotReversedOnReturn(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90, 9000)->id);

        // Достижения B до возврата.
        DB::table('v2_rank_history')->insert([
            'member_id' => $ids['b'], 'rank_code' => 'MANAGER', 'rank_ordinal' => 4,
            'achieved_at' => now(), 'evaluation_id' => null, 'policy_version_id' => 1, 'created_at' => now(),
        ]);
        $award = AwardEntitlement::query()->create([
            'member_id' => $ids['b'], 'award_code' => 'MANAGER', 'stage_no' => 1,
            'amount_cents' => 10000, 'trigger_type' => 'rank_achieved',
            'status' => 'granted', 'granted_at' => now(), 'idempotency_key' => 'aw-b-1',
        ]);

        $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'возврат', null, 'k-rank');

        // Ранг/награда/тир — навсегда.
        $this->assertSame(1, DB::table('v2_rank_history')->where('member_id', $ids['b'])->count());
        $this->assertSame('granted', $award->fresh()->status);
        $this->assertSame(1, DB::table('v2_tier_history')->where('member_id', $ids['b'])->count());

        // Провенанс неотзываемости + уменьшения PV-базы будущих квалификаций.
        $return = OrderReturn::query()->where('order_id', $orderId)->sole();
        $note = ReversalAction::query()->where('return_id', $return->id)
            ->where('action_type', ReversalAction::TYPE_QUALIFICATION_NOTE)->sole();
        $this->assertFalse($note->snapshot_json['rank_reversed']);
        $this->assertFalse($note->snapshot_json['award_reversed']);
        $this->assertFalse($note->snapshot_json['tier_downgraded']);
        $this->assertSame(1, ReversalAction::query()->where('return_id', $return->id)
            ->where('action_type', ReversalAction::TYPE_TIER_BASIS_ADJUST)->count());
    }

    public function testE2eFullReturnOpenPeriodRestoresBalancesAndReversesLots(): void
    {
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $this->seedTier($ids['a'], 'BUSINESS');
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90, 9000)->id);

        $this->assertGreaterThan(0, PvLot::query()->where('origin_order_id', $orderId)->count());

        $return = $this->refunds()->create($orderId, OrderReturn::KIND_FULL, [], 'e2e', null, 'k-e2e');

        // Все балансы вернулись к нулю (кроме нерушимых ранга/награды/тира — их тут нет).
        $this->assertSame(0, $this->osBalance($ids['b']));
        $this->assertSame(0, $this->osBalance($ids['a']));
        $this->assertSame(0, $this->osBalance($ids['c']));
        $this->assertSame(OrderReturn::STATUS_REVERSED, $return->status);

        // PV-лоты заказа реверснуты (несматченные → reversed).
        foreach (PvLot::query()->where('origin_order_id', $orderId)->get() as $lot) {
            $this->assertSame(PvLot::STATE_REVERSED, $lot->state);
            $this->assertSame(0, bccomp($lot->pv_available, '0', 6));
        }
        $this->assertGreaterThan(0, ReversalAction::query()->where('return_id', $return->id)
            ->where('action_type', ReversalAction::TYPE_PV_LOT_REVERSAL)->count());
    }
}
