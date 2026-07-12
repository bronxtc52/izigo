<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Models\PvLot;
use Modules\Calculator\V2\Services\Volume\BinaryMatchingService;
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

    /**
     * MF-1 (W2 review): лот, пришедший ПОСЛЕ дедлайна grace, но ДО прогона сканера,
     * не должен застрять в grace_held навсегда. У просроченного CLIENT hold обязан
     * сперва зафиксировать просрочку (expireGrace), а не запирать новый лот в
     * grace_held, откуда аннулирование (occurred_at<=deadline) его уже не достанет.
     */
    public function testIncomingLotForAlreadyExpiredClientDoesNotStickInGraceHeld(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , , $cData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT, дедлайн 04.08
        $root = $this->memberByTg(600);

        // Дедлайн уже прошёл, сканер ещё НЕ прогонялся; покупка даунлайна C создаёт
        // FREE-лот владельца root с occurred_at ПОСЛЕ дедлайна.
        $this->travelTo(CarbonImmutable::parse('2026-08-06 10:00:00', 'UTC'));
        $this->buyAndPay($cData, $p100->id);

        // Инвариант MF-1/architect-3: у просроченного клиента НЕТ застрявших grace_held.
        $rootLots = PvLot::query()->where('owner_member_id', $root->id)->get();
        $this->assertSame(0, $rootLots->where('state', PvLot::STATE_GRACE_HELD)->count(),
            'лот после дедлайна не должен запираться в grace_held');

        // hold обязан был прогнать владельца через expireGrace ДО себя.
        $state = PartnerState::query()->find($root->id);
        $this->assertSame(PartnerState::STATE_GRACE_EXPIRED, $state->state);
        $this->assertSame(PartnerState::OUTCOME_ANNULLED, $state->grace_outcome);
        $this->travelBack();
    }

    /**
     * architect-3 (W2 review): на терминальном исходе grace аннулируются ВСЕ grace_held
     * лоты владельца, а не только те, что попали в окно occurred_at<=deadline. Иначе
     * лот, оказавшийся в grace_held с occurred_at после дедлайна, переживает expiry.
     */
    public function testGraceExpiryAnnulsAllGraceHeldRegardlessOfOccurredAt(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, , , $cData] = $this->tree();

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT
        $root = $this->memberByTg(600);

        // Покупка C в пределах grace -> реальный grace_held лот владельца root.
        $this->buyAndPay($cData, $p100->id);
        $held = PvLot::query()->where('owner_member_id', $root->id)
            ->where('state', PvLot::STATE_GRACE_HELD)->get();
        $this->assertGreaterThanOrEqual(1, $held->count());

        // Симулируем лот, попавший в grace_held с occurred_at ПОСЛЕ дедлайна
        // (граничный сценарий architect-3).
        PvLot::query()->whereIn('id', $held->pluck('id'))
            ->update(['occurred_at' => CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC')]);

        // Дедлайн прошёл — сканер должен аннулировать ВСЕ grace_held.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();

        $after = PvLot::query()->where('owner_member_id', $root->id)->get();
        $this->assertSame(0, $after->where('state', PvLot::STATE_GRACE_HELD)->count(),
            'grace_expired => ноль grace_held (единый инвариант)');
        $this->assertTrue($after->where('id', $held->first()->id)->first()->pv_reversed > 0);
        $this->travelBack();
    }

    /**
     * MF-1b (W2 review, high): пост-дедлайновые FREE-лоты владельца, чей grace истёк
     * (grace_expired, НЕ активировался в CONSULTANT), НЕ должны потребляться матчингом
     * T03 — PV не засчитывается в пары, пока владелец не станет CONSULTANT (BR-REG-004,
     * изоляция grace). Когда владелец ПОЗЖЕ активируется (личный реферал после дедлайна:
     * grace_expired -> CONSULTANT) — те же лоты легитимно матчатся: PV не теряется,
     * лишь откладывается.
     *
     * Дерево: root -> {A(лево), B(право)}; C под A, D под B (бинарные внуки root, НЕ его
     * личные L1-рефералы — чтобы их пост-дедлайновые покупки не активировали root).
     */
    public function testGraceExpiredOwnerLotsNotMatchableUntilConsultant(): void
    {
        $p100 = $this->product(100, 'P100');
        $this->travelTo(CarbonImmutable::parse('2026-07-05 10:00:00', 'UTC'));
        [$rootData, $rootRef] = $this->registerTg(600, name: 'Root');
        [$aData, $aRef] = $this->registerTg(601, $rootRef, 'A');
        [$bData, $bRef] = $this->registerTg(602, $rootRef, 'B');
        [$cData] = $this->registerTg(603, $aRef, 'C');
        [$dData] = $this->registerTg(604, $bRef, 'D');

        $this->buyAndPay($rootData, $p100->id); // root -> CLIENT, дедлайн 04.08
        $root = $this->memberByTg(600);

        // Дедлайн прошёл — сканер фиксирует просрочку: root -> grace_expired.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 00:00:00', 'UTC'));
        $this->artisan('calc-v2:client-grace-scan')->assertSuccessful();
        $this->assertSame(PartnerState::STATE_GRACE_EXPIRED,
            PartnerState::query()->find($root->id)->state);

        // Пост-дедлайновые покупки внуков C(лево)/D(право) => FREE-лоты владельца root на
        // обеих ветках (root grace_expired, не client => hold не срабатывает, лоты FREE).
        $this->buyAndPay($cData, $p100->id);
        $this->buyAndPay($dData, $p100->id);
        $freeLots = PvLot::query()->where('owner_member_id', $root->id)
            ->where('state', PvLot::STATE_FREE)->get();
        $this->assertSame(2, $freeLots->count());
        $this->assertSame(['left', 'right'], $freeLots->pluck('side')->sort()->values()->all(),
            'предпосылка: FREE-лоты на обеих ветках (иначе матч был бы 0 и без бага)');
        // root всё ещё grace_expired — внуки не его L1-рефералы.
        $this->assertSame(PartnerState::STATE_GRACE_EXPIRED,
            PartnerState::query()->find($root->id)->state);

        $svc = app(BinaryMatchingService::class);
        $cutoff = CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC');

        // КРАСНОЕ: min(лево,право)=100 «на бумаге», но владелец не активирован =>
        // матчинг НЕ засчитывает его PV.
        $m1 = $svc->runMatching($root->id, $cutoff, '2026-08-H2', 'run-expired');
        $this->assertSame(0, bccomp($m1->matched_pv, '0', 6),
            'лоты grace_expired-владельца не должны матчиться до активации');
        $this->assertSame(0, DB::table('v2_pv_lot_allocations')
            ->where('binary_match_id', $m1->id)->count());
        // PV не потерян — лоты остались FREE и нетронуты.
        $this->assertSame(2, PvLot::query()->where('owner_member_id', $root->id)
            ->where('state', PvLot::STATE_FREE)->count());

        // Владелец активируется: личный реферал A оплачивает ПОСЛЕ дедлайна =>
        // grace_expired -> CONSULTANT (лестница продолжается).
        $this->buyAndPay($aData, $p100->id);
        $this->assertSame(PartnerState::STATE_CONSULTANT,
            PartnerState::query()->find($root->id)->state);

        // ЗЕЛЁНОЕ: те же (ранее пропущенные) лоты теперь матчабельны — PV лишь отложился.
        $m2 = $svc->runMatching($root->id, $cutoff, '2026-08-H2', 'run-consultant');
        $this->assertSame(0, bccomp($m2->matched_pv, '100', 6),
            'после активации владельца отложенный PV матчится');
        $this->assertGreaterThan(0, DB::table('v2_pv_lot_allocations')
            ->where('binary_match_id', $m2->id)->count());
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
