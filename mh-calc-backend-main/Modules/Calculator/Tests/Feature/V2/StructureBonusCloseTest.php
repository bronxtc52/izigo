<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Tests\TestCase;

/**
 * T06 [ДЕНЬГИ, обязательный]: интеграция структурной премии через боевой close-pipeline
 * (реальные лоты из оплаченных заказов → matching T03 → капы → posting на НС).
 * Голден CAL-BIN-001, направление денег (НС, ОС не трогается), капы+сгорание,
 * месячная safety H1+H2, eligibility (CLIENT лоты не потребляются), нулевая строка,
 * идемпотентность, инвариант двойной записи, закрытый период.
 */
class StructureBonusCloseTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2Status;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
        // volume-capture в точке оплаты + движок V2 (гейтит close-шаги T06, deny-by-default).
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_plan_v2_engine');
        $this->activateV2Policy();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Хелперы
    // ------------------------------------------------------------------

    private function product(int $bvCents, int $pv, string $sku): Product
    {
        return Product::query()->create([
            'name' => $sku, 'price_usdt_cents' => $bvCents, 'bv_usd_cents' => $bvCents, 'pv' => $pv,
            'package_id' => 1, 'sku' => $sku, 'is_active' => true, 'sort' => 1,
        ]);
    }

    private function postWebhook(array $payload): TestResponse
    {
        $json = json_encode($payload);
        $sig = hash_hmac('sha256', $json, $this->secret);

        return $this->call('POST', '/api/v1/webhooks/wallet-pay', [], [], [], [
            'HTTP_X_FAKE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }

    private function buyAndPay(string $data, int $productId): void
    {
        $orderId = $this->postJson('/api/v1/cabinet/orders',
            ['product_id' => $productId], $this->tgHeaders($data))->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($data))->json('data');
        $this->postWebhook([
            'external_ref' => "pay:{$pay['payment_id']}", 'status' => 'paid', 'amount_cents' => $pay['amount_cents'],
        ])->assertOk();
    }

    /** root + A(left) + B(right); обе ноги покупают продукт => у root лоты одинакового BV L/R. */
    private function seedRootBothLegs(Product $product): int
    {
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $this->buyAndPay($aData, $product->id);
        $this->buyAndPay($bData, $product->id);

        return $this->memberByTg(600)->id;
    }

    private function account(int $memberId): MemberAccountV2
    {
        return MemberAccountV2::query()->where('member_id', $memberId)->firstOrFail();
    }

    /** НС участника; 0 если счёта ещё нет (никаких проводок не было). */
    private function nsCents(int $memberId): int
    {
        return (int) (MemberAccountV2::query()->where('member_id', $memberId)->value('ns_cents') ?? 0);
    }

    private function assertAllTransactionsBalanced(): void
    {
        foreach (LedgerEntry::query()->pluck('tx_id')->unique() as $txId) {
            $legs = LedgerEntry::where('tx_id', $txId)->get();
            $this->assertSame(
                (int) $legs->where('direction', 'debit')->sum('amount_cents'),
                (int) $legs->where('direction', 'credit')->sum('amount_cents'),
                "tx {$txId} unbalanced",
            );
        }
    }

    private function closer(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    // ------------------------------------------------------------------
    // Голден + направление денег
    // ------------------------------------------------------------------

    public function testGoldenManagerCreditsNsAndLeavesOsUntouched(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE'); // BV 9000, matched_bv=9000
        $rootId = $this->seedRootBothLegs($bronze);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        $row = StructureBonus::query()->where('member_id', $rootId)->sole();
        $this->assertSame('MANAGER', $row->rank_code);
        $this->assertSame(500, $row->rate_bps);
        $this->assertSame(9000, $row->matched_bv_cents);
        $this->assertSame(450, $row->gross_cents);      // 9000 * 5%
        $this->assertSame(450, $row->after_cap_cents);
        $this->assertSame(450, $row->net_cents);
        $this->assertSame(0, $row->forfeited_cents);
        $this->assertSame('2026-07', $row->accrual_month);
        $this->assertSame(StructureBonus::STATUS_POSTED, $row->status);
        $this->assertSame('v2:structure:' . $row->period_id . ':' . $rootId, $row->posting_idempotency_key);

        // Направление денег: премия на НС; ОС/held не тронуты.
        $acct = $this->account($rootId);
        $this->assertSame(450, $acct->ns_cents);
        $this->assertSame(0, $acct->os_available_cents);
        $this->assertSame(0, $acct->os_held_cents);
        $this->assertAllTransactionsBalanced();
    }

    // ------------------------------------------------------------------
    // Капы + сгорание
    // ------------------------------------------------------------------

    public function testHalfCapClampsAndForfeitsExcess(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        // BV 1_200_000 * 5% = 60000 gross > MANAGER half_cap 50000.
        $big = $this->product(1_200_000, 90, 'TARIFF-BIG');
        $rootId = $this->seedRootBothLegs($big);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        $row = StructureBonus::query()->where('member_id', $rootId)->sole();
        $this->assertSame(60000, $row->gross_cents);
        $this->assertSame(50000, $row->after_cap_cents);
        $this->assertSame(10000, $row->forfeited_cents); // сматченный сверх капа сгорел, дельта видна
        $this->assertSame(50000, $this->account($rootId)->ns_cents);
        $this->assertAllTransactionsBalanced();
    }

    public function testMonthlySafetyAcrossHalfMonths(): void
    {
        // H1: обе ноги покупают big → after_cap 50000. H2: докупают → monthly остаток 50000.
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $big = $this->product(1_200_000, 90, 'TARIFF-BIG');
        $rootId = $this->seedRootBothLegs($big);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');
        $this->assertSame(50000, $this->account($rootId)->ns_cents);

        // Новые лоты во втором окне (обе ноги докупают, участники уже существуют).
        $this->travelTo(Carbon::parse('2026-07-20 10:00:00', 'UTC'));
        $this->buyAndPay($this->initData(601, null, 'A'), $big->id);
        $this->buyAndPay($this->initData(602, null, 'B'), $big->id);

        $this->travelTo(Carbon::parse('2026-08-01 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H2');

        $h2 = StructureBonus::query()->where('member_id', $rootId)->orderByDesc('period_id')->first();
        $this->assertSame(60000, $h2->gross_cents);
        $this->assertSame(50000, $h2->cap_remaining_before_cents); // остаток месяца ДО H2
        $this->assertSame(50000, $h2->after_cap_cents);
        // Σ after_cap двух окон = 100000 = monthly cap; НС = 100000.
        $this->assertSame(100000, $this->account($rootId)->ns_cents);
        $this->assertAllTransactionsBalanced();
    }

    // ------------------------------------------------------------------
    // Eligibility + нулевая строка
    // ------------------------------------------------------------------

    public function testClientRankLotsNotConsumedNoBonus(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE');
        $rootId = $this->seedRootBothLegs($bronze);
        // Ранга НЕ сеем (rankAsOf вернёт null) — root не участник структурной премии.

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        $this->assertSame(0, StructureBonus::query()->count());
        // Лоты остались free — не потреблены.
        $this->assertSame(2, PvLot::query()->where('owner_member_id', $rootId)
            ->where('state', PvLot::STATE_FREE)->count());
        $this->assertSame(0, $this->nsCents($rootId));
    }

    public function testConsultantWithOneLegYieldsZeroRow(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE');
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A'); // только левая нога
        $this->buyAndPay($aData, $bronze->id);
        $rootId = $this->memberByTg(600)->id;
        $this->seedRank($rootId, StatusCode::CONSULTANT, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        $row = StructureBonus::query()->where('member_id', $rootId)->sole();
        $this->assertSame(0, $row->gross_cents);
        $this->assertSame(0, $row->after_cap_cents);
        $this->assertSame(0, $row->net_cents);
        $this->assertSame(StructureBonus::STATUS_POSTED, $row->status);
        $this->assertSame(0, $this->nsCents($rootId));
        // Левый лот не потреблён (нет пары) — остаётся free.
        $this->assertSame(1, PvLot::query()->where('owner_member_id', $rootId)
            ->where('state', PvLot::STATE_FREE)->count());
    }

    // ------------------------------------------------------------------
    // Идемпотентность + закрытый период
    // ------------------------------------------------------------------

    public function testIdempotentReCloseIsNoOp(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE');
        $rootId = $this->seedRootBothLegs($bronze);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');
        $this->closer()->closeHalfMonth('2026-07-H1'); // повтор — окно уже succeeded, no-op

        $this->assertSame(1, StructureBonus::query()->where('member_id', $rootId)->count());
        $this->assertSame(450, $this->account($rootId)->ns_cents); // не задвоилось
        // Ровно одна bonus_v2-проводка НС для этого начисления (alreadyPosted no-op).
        $this->assertSame(1, LedgerEntry::query()
            ->where('member_id', $rootId)->where('source_type', 'bonus_v2')
            ->where('direction', 'credit')->count());
        $this->assertAllTransactionsBalanced();
    }

    public function testRerunOnClosedPeriodViaCommandRejected(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_engine');
        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE');
        $rootId = $this->seedRootBothLegs($bronze);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        // Закрытый период: ручной пере-прогон отвергается (контракт T04, assertOpen).
        $this->artisan('v2:structure-bonus:run', ['period' => '2026-07-H1'])
            ->assertExitCode(1);
        // НС не изменился повторным прогоном.
        $this->assertSame(450, $this->account($rootId)->ns_cents);
    }

    public function testEngineFlagOffExcludesStructureSteps(): void
    {
        // Deny-by-default: mh_plan_v2_engine OFF => close-шаги T06 вырезаны из пайплайна.
        app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->set('mh_plan_v2_engine', false);

        $this->travelTo(Carbon::parse('2026-07-05 10:00:00', 'UTC'));
        $bronze = $this->product(9000, 90, 'TARIFF-BRONZE');
        $rootId = $this->seedRootBothLegs($bronze);
        $this->seedRank($rootId, StatusCode::MANAGER, Carbon::parse('2026-07-05', 'UTC')->toImmutable());

        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1');

        $this->assertSame(0, StructureBonus::query()->count()); // ни одной строки
        $this->assertSame(0, $this->nsCents($rootId)); // денег нет
        $this->assertSame(2, PvLot::query()->where('owner_member_id', $rootId)
            ->where('state', PvLot::STATE_FREE)->count()); // лоты не тронуты
    }

    public function testPeriodStoresPeriodTypeHalfMonth(): void
    {
        // Санити: наш шаг применяется только к half_month (не month/quarter).
        $this->travelTo(Carbon::parse('2026-07-16 00:10:00', 'UTC'));
        $period = app(\Modules\Calculator\V2\Services\Periods\PeriodService::class)
            ->ensureByCode('2026-07-H1');
        $this->assertSame(CalcPeriod::TYPE_HALF_MONTH, $period->period_type);
    }
}
