<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Models\ReferralReward;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Modules\Calculator\V2\Services\Referral\ReferralBonusService;
use Tests\TestCase;

/**
 * T07 [ДЕНЬГИ, CAL-REF-001]: реферальная премия по тирам на боевом пути оплаты
 * (webhook → markPaid → PaidOrderV2Pipeline → ReferralBonusStep). L1 10% прямому
 * спонсору, L2 0/5/8% по тиру спонсора-2; на ОС сразу, целочисленная математика,
 * идемпотентность, stop_at_elite (дефолт FALSE), deny-by-default, RBAC.
 */
class ReferralBonusTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private string $secret = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** Активировать каноническую политику (опц. со stop_at_elite=true). */
    private function activatePolicy(bool $stopAtElite = false): void
    {
        $doc = DefaultPolicyConfig::doc();
        $doc['referral']['stop_at_elite'] = $stopAtElite;
        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-t07-' . ($stopAtElite ? 'stop' : 'nostop'), $doc, null);
        $service->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
    }

    private function product(int $priceCents = 9000, int $pv = 90, ?int $bvCents = null): Product
    {
        return Product::query()->create([
            'name' => 'Tariff', 'price_usdt_cents' => $priceCents, 'pv' => $pv,
            'bv_usd_cents' => $bvCents, 'package_id' => 1, 'sku' => 'TARIFF-T07',
            'is_active' => true, 'sort' => 1,
        ]);
    }

    /** Реф-цепочка A ← B ← C (sponsor_id). Возвращает [aData, bData, cData, ids]. */
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

    private function seedTier(int $memberId, string $tier, ?int $sourceOrderId = null, ?CarbonImmutable $at = null): void
    {
        DB::table('v2_tier_history')->insertOrIgnore([
            'member_id' => $memberId,
            'tier' => $tier,
            'tier_before' => null,
            'basis_personal_pv' => '0',
            'source_order_id' => $sourceOrderId,
            'policy_version_id' => 1,
            'effective_at' => $at ?? CarbonImmutable::parse('2026-02-01', 'UTC'),
            'created_at' => now(),
        ]);
    }

    private function buyAndPay(string $data, int $productId): int
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
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

    // ------------------------------------------------------------------ money

    public function testHappyPathL1AndL2CreditOsWithLotsAndBalancedLedger(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');     // L1 всегда 10% независимо от тира
        $this->seedTier($ids['a'], 'BUSINESS');  // L2 = 5%

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        // L1: B получает 10% от 9000 = 900.
        $l1 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->sole();
        $this->assertSame($ids['b'], $l1->beneficiary_member_id);
        $this->assertSame($ids['c'], $l1->source_member_id);
        $this->assertSame(1000, $l1->rate_bps);
        $this->assertSame(9000, $l1->base_bv_cents);
        $this->assertSame(900, $l1->gross_cents);
        $this->assertNull($l1->net_cents);
        $this->assertSame(ReferralReward::STATUS_POSTED, $l1->status);
        $this->assertSame('ELITE', $l1->tier_snapshot);

        // L2: A (BUSINESS) получает 5% от 9000 = 450.
        $l2 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 2)->sole();
        $this->assertSame($ids['a'], $l2->beneficiary_member_id);
        $this->assertSame(500, $l2->rate_bps);
        $this->assertSame(450, $l2->gross_cents);
        $this->assertSame(ReferralReward::STATUS_POSTED, $l2->status);

        // ОС начислен обоим.
        $this->assertSame(900, $this->osBalance($ids['b']));
        $this->assertSame(450, $this->osBalance($ids['a']));

        // Кредит-лоты ОС на 1 год с source_type=referral.
        $lot = WalletLotV2::query()->where('member_id', $ids['b'])->where('account', WalletLotV2::ACCOUNT_OS)->sole();
        $this->assertSame(900, $lot->amount_cents);
        $this->assertSame('referral', $lot->source_type);
        $this->assertNotNull($lot->expires_at);

        // Двойная запись сходится по группе проводок реферальной (Σdebit = Σcredit).
        $this->assertLedgerBalanced($l1->ledger_idempotency_key);
        $this->assertLedgerBalanced($l2->ledger_idempotency_key);
    }

    public function testL2StartTierYieldsZeroRateRowWithoutLedger(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'START');
        $this->seedTier($ids['a'], 'START'); // L2 START = 0%

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        $l2 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 2)->sole();
        $this->assertSame(0, $l2->rate_bps);
        $this->assertSame(0, $l2->gross_cents);
        $this->assertSame(ReferralReward::STATUS_ZERO_RATE, $l2->status);
        $this->assertNull($l2->ledger_idempotency_key);
        $this->assertSame(0, $this->osBalance($ids['a']));
        // L1 всё равно платится (10%).
        $this->assertSame(900, $this->osBalance($ids['b']));
    }

    public function testL2EliteTierEightPercent(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'BUSINESS');
        $this->seedTier($ids['a'], 'ELITE'); // L2 = 8%

        $orderId = $this->buyAndPay($ids['cData'], $this->product(10000, 100)->id);

        $l2 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 2)->sole();
        $this->assertSame(800, $l2->rate_bps);
        $this->assertSame(800, $l2->gross_cents); // 10000 * 8% = 800
        $this->assertSame(800, $this->osBalance($ids['a']));
    }

    public function testBeneficiaryWithoutTierGetsZeroRate(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        // Ни B, ни A не имеют тира (ниже START) — реферальная не начисляется.

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        foreach ([1, 2] as $depth) {
            $row = ReferralReward::query()->where('order_id', $orderId)->where('depth', $depth)->sole();
            $this->assertSame(0, $row->rate_bps);
            $this->assertSame(ReferralReward::STATUS_ZERO_RATE, $row->status);
            $this->assertNull($row->tier_snapshot);
        }
        $this->assertSame(0, $this->osBalance($ids['b']));
        $this->assertSame(0, $this->osBalance($ids['a']));
    }

    public function testRoundingFloorsToCentInCompanyFavour(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'BUSINESS');
        $this->seedTier($ids['a'], 'ELITE'); // 8%

        // Образец PPTX: 1 137 240 * 8% = 90 979.2 → 90 979 (floor).
        $orderId = $this->buyAndPay($ids['cData'], $this->product(1_137_240, 90, 1_137_240)->id);

        $l2 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 2)->sole();
        $this->assertSame(90_979, $l2->gross_cents);
        $this->assertSame(90_979, $this->osBalance($ids['a']));
        // Остаток дроби зафиксирован в explain (провенанс округления, DEC-054).
        $this->assertSame(2000, $l2->explain['rounding_remainder_micro']); // (1137240*800) % 10000
    }

    public function testStopAtEliteBlocksWhenBuyerAlreadyElite(): void
    {
        $this->activatePolicy(stopAtElite: true);
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'BUSINESS');
        $this->seedTier($ids['a'], 'ELITE');
        // Покупатель C уже ELITE от ПРОШЛОГО заказа (source_order_id NULL/не текущий → «уже ELITE»).
        $this->seedTier($ids['c'], 'ELITE');

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        foreach ([1, 2] as $depth) {
            $row = ReferralReward::query()->where('order_id', $orderId)->where('depth', $depth)->sole();
            $this->assertSame(ReferralReward::STATUS_BLOCKED_ELITE, $row->status);
            $this->assertNull($row->ledger_idempotency_key);
        }
        $this->assertSame(0, $this->osBalance($ids['b']));
        $this->assertSame(0, $this->osBalance($ids['a']));
    }

    public function testCrossingOrderPaysEvenWhenStopEnabled(): void
    {
        $this->activatePolicy(stopAtElite: true);
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'BUSINESS');
        $this->seedTier($ids['a'], 'ELITE');

        // Сначала создаём заказ (без crossing-строки), затем помечаем C ELITE ЭТИМ заказом.
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $this->product(9000, 90)->id], $this->tgHeaders($ids['cData']))->json('data.id');
        // Crossing: C достигает ELITE именно этим заказом (source_order_id == orderId) — DEC-011.
        $this->seedTier($ids['c'], 'ELITE', sourceOrderId: $orderId);

        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($ids['cData']))->json('data');
        $this->postWebhook(['external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents']])->assertOk();

        $l1 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->sole();
        $this->assertSame(ReferralReward::STATUS_POSTED, $l1->status, 'crossing-заказ платит реферальную (DEC-011)');
        $this->assertSame(900, $this->osBalance($ids['b']));
    }

    public function testStopDisabledByDefaultPaysEvenForEliteBuyer(): void
    {
        $this->activatePolicy(); // stop_at_elite=false (дефолт владельца)
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'BUSINESS');
        $this->seedTier($ids['c'], 'ELITE'); // C давно ELITE (source_order_id NULL)

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        $l1 = ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->sole();
        $this->assertSame(ReferralReward::STATUS_POSTED, $l1->status);
        $this->assertSame(900, $this->osBalance($ids['b']));
    }

    public function testIdempotentReplayCreatesExactlyOneSetOfPostings(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $this->seedTier($ids['a'], 'BUSINESS');

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        // Повтор пост-оплатной обработки (replay webhook/markPaid) — под advisory-lock.
        $this->underActivationLock(fn () => app(ReferralBonusService::class)->onOrderPaid($orderId));
        $this->underActivationLock(fn () => app(ReferralBonusService::class)->onOrderPaid($orderId));

        $this->assertSame(2, ReferralReward::query()->where('order_id', $orderId)->count());
        $this->assertSame(900, $this->osBalance($ids['b']));
        $this->assertSame(450, $this->osBalance($ids['a']));
    }

    public function testNoSponsorProducesNoRewards(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        // Корень без спонсора покупает сам.
        [$rootData] = $this->registerTg(800, name: 'Root');
        $rootId = $this->memberByTg(800)->id;

        $orderId = $this->buyAndPay($rootData, $this->product(9000, 90)->id);

        $this->assertSame(0, ReferralReward::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, $this->osBalance($rootId));
    }

    public function testSingleSponsorYieldsOnlyL1(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        [$aData, $aRef] = $this->registerTg(810, name: 'A');
        [$bData] = $this->registerTg(811, $aRef, 'B');
        $aId = $this->memberByTg(810)->id;
        $this->seedTier($aId, 'ELITE');

        $orderId = $this->buyAndPay($bData, $this->product(9000, 90)->id);

        $this->assertSame(1, ReferralReward::query()->where('order_id', $orderId)->count());
        $this->assertSame(1, ReferralReward::query()->where('order_id', $orderId)->where('depth', 1)->count());
        $this->assertSame(900, $this->osBalance($aId));
    }

    public function testFeatureFlagOffCreatesNoReferralAndLeavesV1Intact(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes'); // mh_v2_referral OFF
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');

        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        $this->assertSame(0, ReferralReward::query()->where('order_id', $orderId)->count());
        $this->assertSame(0, $this->osBalance($ids['b']));
    }

    // ------------------------------------------------------------------ RBAC / API

    public function testCabinetReturnsOwnRewardsOnly(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $this->seedTier($ids['a'], 'BUSINESS');
        $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        // B видит свою L1-премию.
        $resp = $this->getJson('/api/v1/cabinet/v2/referral-rewards', $this->tgHeaders($ids['bData']))->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertSame($ids['b'], $resp->json('data.0.beneficiary_member_id'));

        // C (покупатель) не получатель — пусто.
        $this->getJson('/api/v1/cabinet/v2/referral-rewards', $this->tgHeaders($ids['cData']))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function testCabinetRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_referral');
        $this->getJson('/api/v1/cabinet/v2/referral-rewards', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetFlagOffBlocks(): void
    {
        [$rootData] = $this->registerTg(820, name: 'Root');
        $this->getJson('/api/v1/cabinet/v2/referral-rewards', $this->tgHeaders($rootData))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testAdminRequiresWebAuthNotInitData(): void
    {
        $this->enableFeatureFlags('mh_v2_referral');
        [$rootData] = $this->registerTg(830, name: 'Root');
        // initData (cabinet) не проходит web.admin.
        $this->getJson('/api/v1/admin/v2/referral-rewards', $this->tgHeaders($rootData))
            ->assertStatus(401);
    }

    public function testAdminNoRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_v2_referral');
        [$xData] = $this->registerTg(832, name: 'X'); // без owner/finance
        $this->getJson('/api/v1/admin/v2/referral-rewards', $this->adminHeaders($xData))
            ->assertStatus(403);
    }

    public function testAdminFinanceCanRead(): void
    {
        $this->enableFeatureFlags('mh_v2_referral');
        [$fData] = $this->registerTg(833, name: 'Fin');
        $this->grantRole(833, 'finance'); // read-группа owner,finance (amendments NTH-1)
        $this->getJson('/api/v1/admin/v2/referral-rewards', $this->adminHeaders($fData))
            ->assertOk()->assertJsonPath('status', 'success');
    }

    public function testAdminOwnerListsWithFilters(): void
    {
        $this->activatePolicy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_referral');
        $ids = $this->chain();
        $this->seedTier($ids['b'], 'ELITE');
        $this->seedTier($ids['a'], 'BUSINESS');
        $orderId = $this->buyAndPay($ids['cData'], $this->product(9000, 90)->id);

        [$ownerData] = $this->registerTg(840, name: 'Owner');
        $this->grantRole(840, 'owner');
        $headers = $this->adminHeaders($ownerData);

        $this->getJson('/api/v1/admin/v2/referral-rewards', $headers)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/admin/v2/referral-rewards?depth=&beneficiary_member_id={$ids['b']}", $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.depth', 1);
        $this->getJson("/api/v1/admin/v2/referral-rewards?order_id={$orderId}", $headers)
            ->assertOk()->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------------ util

    /** Проверить, что группа проводок сбалансирована (Σdebit == Σcredit). idempotency_key
     *  живёт только на первой проводке группы → резолвим tx_id и суммируем по нему. */
    private function assertLedgerBalanced(string $idempotencyKey): void
    {
        $txId = DB::table('ledger_entries')->where('idempotency_key', $idempotencyKey)->value('tx_id');
        $this->assertNotNull($txId, "Проводка с ключом {$idempotencyKey} не найдена");
        $row = DB::table('ledger_entries')
            ->where('tx_id', $txId)
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE 0 END) AS dr")
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount_cents ELSE 0 END) AS cr")
            ->first();
        $this->assertSame((int) $row->dr, (int) $row->cr, "Группа {$idempotencyKey} не сбалансирована");
        $this->assertGreaterThan(0, (int) $row->dr);
    }
}
