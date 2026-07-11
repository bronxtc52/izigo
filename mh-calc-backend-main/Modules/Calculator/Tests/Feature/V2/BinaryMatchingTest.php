<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Contracts\PvLotService;
use Modules\Calculator\V2\Models\BinaryMatch;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
use Modules\Calculator\V2\Services\Volume\PvLotVolumeService;
use Tests\TestCase;

/**
 * T03 [ДЕНЬГИ]: матчинг min(L,R) на реальных лотах из оплаченных заказов.
 * Идемпотентность прогона по (member, period_key, run_uuid); carryover переживает
 * периоды и не сгорает (DEC-018); cutoff-гигиена полуоткрытого окна; финализация
 * периода; инвариант лота available+matched+reversed=original на каждом шаге.
 */
class BinaryMatchingTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $secret = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        config([
            'calculator.payment_gateway' => 'fake',
            'calculator.walletpay_webhook_secret' => $this->secret,
        ]);
        $this->enableFeatureFlags('mh_v2_volumes');
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
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

    /** root + A(left) + B(right); A и B покупают Bronze => у root лоты 90L/90R. */
    private function seedRootWithBothLegs(): int
    {
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);

        return $this->memberByTg(600)->id;
    }

    private function service(): BinaryMatchingService
    {
        return app(BinaryMatchingService::class);
    }

    private function assertAllLotsBalanced(): void
    {
        foreach (PvLot::query()->get() as $lot) {
            $sum = bcadd(bcadd($lot->pv_available, $lot->pv_matched, 6), $lot->pv_reversed, 6);
            $this->assertSame(
                0,
                bccomp($sum, $lot->pv_original, 6),
                "Лот {$lot->id}: available+matched+reversed != original"
            );
        }
    }

    public function testGoldenEqualLegsFullMatch(): void
    {
        // AT-BIN-001 на живых данных: 90PV vs 90PV (Bronze 9000 центов) => matched 90 / BV 9000.
        $rootId = $this->seedRootWithBothLegs();

        $match = $this->service()->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');

        $this->assertSame(0, bccomp($match->matched_pv, '90', 6));
        $this->assertSame(9000, $match->matched_bv_usd_cents);
        $this->assertSame(BinaryMatch::STATUS_PROVISIONAL, $match->status);
        $this->assertSame(2, $match->allocations()->count());

        // Обе стороны выпиты; carry 0; роли веток — tie.
        foreach (PvLot::query()->where('owner_member_id', $rootId)->get() as $lot) {
            $this->assertSame(PvLot::STATE_EXHAUSTED, $lot->state);
            $this->assertSame(0, bccomp($lot->pv_available, '0', 6));
        }
        $stats = DB::table('v2_member_branch_stats')->where('member_id', $rootId)->first();
        $this->assertNull($stats->large_side); // tie
        $this->assertSame(0, bccomp($stats->left_free_pv, '0', 6));
        $this->assertSame(0, bccomp($stats->small_branch_lifetime_pv, '90', 6));
        $this->assertAllLotsBalanced();
    }

    public function testPartialMatchLeavesCarryoverAndLargeSide(): void
    {
        // AT-BIN-002: 180 слева (две покупки A) vs 90 справа => matched 90, carry 90 слева,
        // сторона с остатком становится большой.
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $rootId = $this->memberByTg(600)->id;

        $match = $this->service()->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');

        $this->assertSame(0, bccomp($match->matched_pv, '90', 6));
        $this->assertSame(9000, $match->matched_bv_usd_cents);

        $stats = DB::table('v2_member_branch_stats')->where('member_id', $rootId)->first();
        $this->assertSame('left', $stats->large_side);
        $this->assertSame(0, bccomp($stats->left_free_pv, '90', 6));   // carry
        $this->assertSame(0, bccomp($stats->right_free_pv, '0', 6));
        $this->assertSame(0, bccomp($stats->small_branch_lifetime_pv, '90', 6));

        // FIFO: выпит ПЕРВЫЙ левый лот, второй нетронут.
        $leftLots = PvLot::query()->where('owner_member_id', $rootId)->where('side', 'left')
            ->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertSame(PvLot::STATE_EXHAUSTED, $leftLots[0]->state);
        $this->assertSame(PvLot::STATE_FREE, $leftLots[1]->state);
        $this->assertSame(0, bccomp($leftLots[1]->pv_available, '90', 6));
        $this->assertAllLotsBalanced();
    }

    public function testRepeatRunSameKeyIsNoOp(): void
    {
        $rootId = $this->seedRootWithBothLegs();
        $cutoff = now()->addMinute();

        $first = $this->service()->runMatching($rootId, $cutoff, '2026-07-H1', 'run-1');
        $lotsAfterFirst = PvLot::query()->get()->map(fn ($l) => $l->only(['id', 'pv_available', 'pv_matched']))->all();

        $second = $this->service()->runMatching($rootId, $cutoff, '2026-07-H1', 'run-1');

        $this->assertSame($first->id, $second->id); // тот же матч, не новый
        $this->assertSame(1, BinaryMatch::query()->count());
        $this->assertSame(2, DB::table('v2_pv_lot_allocations')->count());
        $this->assertEquals(
            $lotsAfterFirst,
            PvLot::query()->get()->map(fn ($l) => $l->only(['id', 'pv_available', 'pv_matched']))->all()
        );
    }

    public function testCarryoverSurvivesIntoNextPeriodFifo(): void
    {
        // Период 1: 180L vs 90R => carry 90L. Период 2: B докупает 90R =>
        // carry матчится СЛЕДУЮЩИМ прогоном (FIFO), ничего не сгорело.
        $bronze = $this->bronze();
        [, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData] = $this->registerTg(601, $rootRef, 'A');
        [$bData] = $this->registerTg(602, $rootRef, 'B');
        $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($aData, $bronze->id);
        $this->buyAndPay($bData, $bronze->id);
        $rootId = $this->memberByTg(600)->id;

        $m1 = $this->service()->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'period:2026-07-H1');
        $this->assertSame(0, bccomp($m1->matched_pv, '90', 6));

        $this->buyAndPay($bData, $bronze->id); // новое право на матч справа

        $m2 = $this->service()->runMatching($rootId, now()->addMinute(), '2026-07-H2', 'period:2026-07-H2');
        $this->assertSame(0, bccomp($m2->matched_pv, '90', 6));
        $this->assertSame(9000, $m2->matched_bv_usd_cents);

        // Всё выпито: суммарно 180 сматчено с каждой стороны.
        $stats = DB::table('v2_member_branch_stats')->where('member_id', $rootId)->first();
        $this->assertSame(0, bccomp($stats->left_free_pv, '0', 6));
        $this->assertSame(0, bccomp($stats->right_free_pv, '0', 6));
        $this->assertNull($stats->large_side); // lifetime 180 = 180
        $this->assertAllLotsBalanced();
    }

    public function testCutoffExcludesLaterLots(): void
    {
        $rootId = $this->seedRootWithBothLegs();

        // Cutoff В ПРОШЛОМ (до покупок) — лоты за окном не потребляются.
        $match = $this->service()->runMatching(
            $rootId, now()->subHour(), '2026-06-H2', 'run-old'
        );

        $this->assertSame(0, bccomp($match->matched_pv, '0', 6));
        $this->assertSame(0, $match->matched_bv_usd_cents);
        $this->assertSame(0, DB::table('v2_pv_lot_allocations')->count());
        $this->assertSame(2, PvLot::query()->where('state', PvLot::STATE_FREE)->count());
    }

    public function testFinalizeForPeriodFlipsProvisionalToFinal(): void
    {
        $rootId = $this->seedRootWithBothLegs();
        $this->service()->runMatching($rootId, now()->addMinute(), '2026-07-H1', 'run-1');

        $updated = $this->service()->finalizeForPeriod('2026-07-H1');

        $this->assertSame(1, $updated);
        $this->assertSame(BinaryMatch::STATUS_FINAL, BinaryMatch::query()->sole()->status);
        // Повтор — идемпотентный no-op.
        $this->assertSame(0, $this->service()->finalizeForPeriod('2026-07-H1'));
    }

    public function testRunMatchingForPeriodContractIsIdempotent(): void
    {
        // Контракт V2\Contracts\PvLotService::runMatchingForPeriod: детерминированный
        // run_uuid 'period:{code}' — повтор закрытия периода не плодит матчи.
        $this->seedRootWithBothLegs();
        $now = CarbonImmutable::now('UTC');
        $code = $now->format('Y-m') . ($now->day <= 15 ? '-H1' : '-H2');

        /** @var PvLotService $svc */
        $svc = app(PvLotService::class);
        $svc->runMatchingForPeriod($code);
        $countAfterFirst = BinaryMatch::query()->count();
        $this->assertGreaterThanOrEqual(1, $countAfterFirst);

        $svc->runMatchingForPeriod($code);
        $this->assertSame($countAfterFirst, BinaryMatch::query()->count());

        $root = BinaryMatch::query()->where('member_id', $this->memberByTg(600)->id)->sole();
        $this->assertSame("period:{$code}", $root->run_uuid);
        $this->assertSame(0, bccomp($root->matched_pv, '90', 6));
    }

    public function testInvalidPeriodCodeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PvLotVolumeService::cutoffForPeriod('2026-13-H3');
    }

    public function testCutoffBoundariesUtc(): void
    {
        $this->assertSame(
            '2026-07-16 00:00:00',
            PvLotVolumeService::cutoffForPeriod('2026-07-H1')->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2026-08-01 00:00:00',
            PvLotVolumeService::cutoffForPeriod('2026-07-H2')->format('Y-m-d H:i:s')
        );
        $this->assertSame(
            '2027-01-01 00:00:00',
            PvLotVolumeService::cutoffForPeriod('2026-12-H2')->format('Y-m-d H:i:s')
        );
    }
}
