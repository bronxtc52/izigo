<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\PvLot;
use Tests\TestCase;

/**
 * T05 [ДЕНЬГИ/КАРЬЕРА]: жизненный цикл CLIENT/grace на боевом пути оплаты
 * (webhook -> markPaid -> PaidOrderV2Pipeline -> StatusesStep). BR-REG-004 /
 * CAL-GRACE-001: активация >= 100 PV, 30-дневный grace (DEC-026 включительно),
 * успех=CONSULTANT+сохранение PV, просрочка=аннулирование (идемпотентно),
 * реферал < 100 PV не квалифицирует.
 */
class ClientLifecycleTest extends TestCase
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
        $this->activateV2Policy();
        $this->enableFeatureFlags('mh_v2_volumes', 'mh_v2_statuses');
    }

    private function product(int $pv, string $sku): Product
    {
        return Product::query()->create([
            'name' => "P{$pv}", 'price_usdt_cents' => $pv * 100, 'pv' => $pv,
            'package_id' => 1, 'sku' => $sku, 'is_active' => true, 'sort' => $pv,
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

    /** root -> A(sponsor root) -> C(sponsor A). Возвращает initData каждого. */
    private function tree(): array
    {
        [$rootData, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData, $aRef] = $this->registerTg(601, $rootRef, 'A');
        [$cData] = $this->registerTg(603, $aRef, 'C');

        return [$rootData, $rootRef, $aData, $cData];
    }

    public function testFirstQualifyingPurchaseMakesClientWithGraceDeadline(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id);

        $root = $this->memberByTg(600);
        $state = PartnerState::query()->find($root->id);
        $this->assertSame(PartnerState::STATE_CLIENT, $state->state);
        $this->assertSame('START', $state->current_tier); // 100 PV => START
        $this->assertSame('CLIENT', $state->current_rank_code);

        // DEC-026: 05.07 + 30 дней = конец 04.08 в Asia/Almaty (включительно).
        $deadlineAlmaty = $state->grace_expires_at->setTimezone('Asia/Almaty');
        $this->assertSame('2026-08-04', $deadlineAlmaty->toDateString());
        $this->assertSame('23:59:59', $deadlineAlmaty->format('H:i:s'));
        $this->travelBack();
    }

    public function testReferralWithinGraceMakesConsultantAndReleasesPv(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , $aData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT (grace)
        $root = $this->memberByTg(600);

        // Личный реферал root (A) оплачивает >= 100 PV в пределах grace.
        $this->travelTo(CarbonImmutable::parse('2026-07-20 10:00:00', 'UTC'));
        $this->buyAndPay($aData, $p100->id);

        $state = PartnerState::query()->find($root->id);
        $this->assertSame(PartnerState::STATE_CONSULTANT, $state->state);
        $this->assertSame(PartnerState::OUTCOME_CONSULTANT, $state->grace_outcome);
        $this->assertNull($state->grace_annulled_at);

        // Лоты root (созданы покупкой A на его стороне) — освобождены, не аннулированы.
        $rootLots = PvLot::query()->where('owner_member_id', $root->id)->get();
        $this->assertTrue($rootLots->every(fn ($l) => $l->state === PvLot::STATE_FREE));
        $this->assertTrue($rootLots->every(fn ($l) => bccomp((string) $l->pv_reversed, '0', 6) === 0));
        $this->travelBack();
    }

    public function testGraceExpiryAnnulsPvIdempotently(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , , $cData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT
        $root = $this->memberByTg(600);

        // Покупка C (спонсор A, НЕ личный реферал root) — grace-PV копятся у root.
        $this->buyAndPay($cData, $p100->id);
        $heldBefore = PvLot::query()->where('owner_member_id', $root->id)
            ->where('state', PvLot::STATE_GRACE_HELD)->count();
        $this->assertGreaterThanOrEqual(1, $heldBefore);

        // Дедлайн прошёл — сканер аннулирует grace-PV.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();

        $state = PartnerState::query()->find($root->id);
        $this->assertSame(PartnerState::STATE_GRACE_EXPIRED, $state->state);
        $this->assertSame(PartnerState::OUTCOME_ANNULLED, $state->grace_outcome);
        $this->assertNotNull($state->grace_annulled_at);

        $lot = PvLot::query()->where('owner_member_id', $root->id)->first();
        $this->assertSame('0.000000', $lot->pv_available);
        $this->assertGreaterThan(0, (float) $lot->pv_reversed);

        // Повторный прогон — идемпотентно (grace_outcome уже annulled), 0 изменений.
        $reversedBefore = PvLot::query()->where('owner_member_id', $root->id)->sum('pv_reversed');
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();
        $reversedAfter = PvLot::query()->where('owner_member_id', $root->id)->sum('pv_reversed');
        $this->assertEquals($reversedBefore, $reversedAfter);
        $this->travelBack();
    }

    public function testReferralBelowThresholdDoesNotQualify(): void
    {
        $p100 = $this->product(100, 'P100');
        $p90 = $this->product(90, 'P90');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , $aData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT
        $root = $this->memberByTg(600);

        // Реферал оплачивает лишь 90 PV — НЕ квалифицирует (порог 100).
        $this->buyAndPay($aData, $p90->id);

        $state = PartnerState::query()->find($root->id);
        $this->assertSame(PartnerState::STATE_CLIENT, $state->state);
        $this->assertNull($state->grace_outcome);
        // A тоже не стал CLIENT (его покупка < 100 PV).
        $aState = PartnerState::query()->find($this->memberByTg(601)->id);
        $this->assertNotSame(PartnerState::STATE_CLIENT, $aState?->state ?? 'none');
        $this->travelBack();
    }

    public function testReferralExactlyOnDeadlineQualifiesInclusive(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , $aData] = $this->tree();
        $this->buyAndPay($rootData, $p100->id);
        $root = $this->memberByTg(600);
        $deadline = PartnerState::query()->find($root->id)->grace_expires_at;

        // Реферал оплачивает РОВНО в момент дедлайна (23:59:59 Алматы) — включительно.
        $this->travelTo(CarbonImmutable::parse($deadline, 'UTC'));
        $this->buyAndPay($aData, $p100->id);

        $this->assertSame(PartnerState::STATE_CONSULTANT, PartnerState::query()->find($root->id)->state);
        $this->travelBack();
    }
}
