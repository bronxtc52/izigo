<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\LeadService;
use Modules\Calculator\Services\Payment\FakeTonPayGateway;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use RuntimeException;
use Tests\TestCase;

/**
 * Лид-окно и изменяемый спонсор (ТЗ 2026-06-23). Лид вне дерева, спонсора можно менять
 * до первой покупки; первая оплата промоутит лида в Member и фиксирует спонсора навсегда;
 * через окно лид открепляется. Плюс личные рефералы (sponsor_id) vs бинар.
 */
class LeadTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        FakeTonPayGateway::reset();
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    public function testLeadCreatedWithSponsorAndWindow(): void
    {
        config(['calculator.lead_window_days' => 7]);
        [, $ref] = $this->registerTg(100);

        $this->makeLead(101, $ref);

        $lead = Lead::where('telegram_id', 101)->first();
        $this->assertNotNull($lead);
        $this->assertSame(Member::where('telegram_id', 100)->value('id'), $lead->sponsor_id);
        $this->assertTrue($lead->expires_at->greaterThan(now()->addDays(6)));
        $this->assertNull($lead->getAttribute('ref_code')); // лид не рекрутирует
    }

    public function testChangeSponsorViaButton(): void
    {
        [, $refA] = $this->registerTg(100);
        [, $refB] = $this->registerTg(200);
        [$leadInit] = $this->makeLead(101, $refA);

        $res = $this->postJson('/api/v1/cabinet/lead/change-sponsor', ['ref_code' => $refB], $this->tgHeaders($leadInit))
            ->assertOk();

        $this->assertSame($refB, $res->json('data.sponsor.ref_code'));
        $this->assertSame(Member::where('telegram_id', 200)->value('id'), Lead::where('telegram_id', 101)->value('sponsor_id'));
    }

    public function testChangeSponsorRejectsUnknownRef(): void
    {
        [, $ref] = $this->registerTg(100);
        [$leadInit] = $this->makeLead(101, $ref);

        $this->postJson('/api/v1/cabinet/lead/change-sponsor', ['ref_code' => 'NOPECODE'], $this->tgHeaders($leadInit))
            ->assertStatus(422);

        // Спонсор не изменился.
        $this->assertSame(Member::where('telegram_id', 100)->value('id'), Lead::where('telegram_id', 101)->value('sponsor_id'));
    }

    public function testChangeSponsorRejectedAfterExpiry(): void
    {
        // Защитный guard на уровне сервиса: смена спонсора у истёкшего лида запрещена.
        // (Через HTTP истёкший лид сперва пере-привязывается middleware при заходе по рефке —
        // это и есть правило «повторный переход открепляет и привязывает заново».)
        $this->registerTg(100);
        [, $refB] = $this->registerTg(200);
        $lead = Lead::query()->create([
            'telegram_id' => 101,
            'sponsor_id' => Member::where('telegram_id', 100)->value('id'),
            'expires_at' => now()->subDay(),
        ]);

        $this->expectException(RuntimeException::class);
        app(LeadService::class)->changeSponsor($lead, $refB);
    }

    public function testSelfReferralGuard(): void
    {
        // Защитный инвариант: ref спонсора с тем же telegram_id, что у лида, запрещён.
        [, $refSelf] = $this->registerTg(800);
        $this->registerTg(801);
        $lead = Lead::query()->create([
            'telegram_id' => 800,
            'sponsor_id' => Member::where('telegram_id', 801)->value('id'),
            'expires_at' => now()->addDays(7),
        ]);

        $this->expectException(RuntimeException::class);
        app(LeadService::class)->changeSponsor($lead, $refSelf);
    }

    public function testExpiredLeadReattachesUnderNewSponsorOnNextVisit(): void
    {
        [, $refA] = $this->registerTg(100);
        [, $refB] = $this->registerTg(200);
        $this->makeLead(101, $refA);
        Lead::where('telegram_id', 101)->update(['expires_at' => now()->subDay()]);

        // Заход по рефке B: просроченный лид откреплён, создаётся свежий под B.
        $res = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($this->initData(101, $refB)))->assertOk();

        $this->assertTrue($res->json('data.is_lead'));
        $this->assertSame($refB, $res->json('data.sponsor.ref_code'));
        $this->assertSame(1, Lead::where('telegram_id', 101)->count());
        $this->assertSame(Member::where('telegram_id', 200)->value('id'), Lead::where('telegram_id', 101)->value('sponsor_id'));
    }

    public function testFirstPurchasePromotesLeadAndLocksSponsor(): void
    {
        config(['calculator.payment_gateway' => 'ton_pay_fake']);
        $bronze = $this->bronze();
        [$rootData, $rootRef] = $this->registerTg(700, name: 'Root');
        $this->registerTg(710); // запасной спонсор для попытки смены после покупки

        // Лид под Root → покупает Bronze → оплата подтверждается → промоушн в Member.
        [$leadInit] = $this->makeLead(701, $rootRef);
        $orderId = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))
            ->assertOk()->json('data.id');
        $pay = $this->postJson("/api/v1/cabinet/orders/{$orderId}/pay", [], $this->tgHeaders($leadInit))
            ->assertOk()->json('data');
        FakeTonPayGateway::fakePay($pay['memo'], $pay['amount_cents']);

        $check = $this->postJson("/api/v1/cabinet/payments/{$pay['payment_id']}/check", [], $this->tgHeaders($leadInit))
            ->assertOk();
        $this->assertSame('paid', $check->json('data.payment_status'));

        // Лид промоутнут: участник создан, активен, под Root; запись лида удалена.
        $this->assertDatabaseMissing('leads', ['telegram_id' => 701]);
        $member = Member::where('telegram_id', 701)->first();
        $this->assertNotNull($member);
        $this->assertSame('active', $member->status);
        $this->assertSame(Member::where('telegram_id', 700)->value('id'), $member->sponsor_id);
        $this->assertNotEmpty($member->ref_code);

        // Теперь это участник, а не лид.
        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($leadInit))->assertOk();
        $this->assertNotEmpty($me->json('data.member.ref_code'));

        // Смена спонсора после покупки запрещена (нет лида → 409).
        $otherRef = Member::where('telegram_id', 710)->value('ref_code');
        $this->postJson('/api/v1/cabinet/lead/change-sponsor', ['ref_code' => $otherRef], $this->tgHeaders($leadInit))
            ->assertStatus(409);
        // Спонсор не изменился.
        $this->assertSame(Member::where('telegram_id', 700)->value('id'), $member->fresh()->sponsor_id);
    }

    public function testSecondLeadOrderStillActivatesAfterPromotion(): void
    {
        // P1: лид создал ДВА заказа до оплаты. Оплата первого промоутит лида; второй
        // оплаченный заказ НЕ должен осиротеть (FK nullOnDelete) — promote переносит все
        // заказы/платежи лида на участника, и второй платёж доисполняется как участник.
        config(['calculator.payment_gateway' => 'ton_pay_fake']);
        $bronze = $this->bronze();
        [, $ref] = $this->registerTg(700, name: 'Root');
        [$leadInit] = $this->makeLead(701, $ref);

        $o1 = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))->json('data.id');
        $o2 = $this->postJson('/api/v1/cabinet/orders', ['product_id' => $bronze->id], $this->tgHeaders($leadInit))->json('data.id');
        $p1 = $this->postJson("/api/v1/cabinet/orders/{$o1}/pay", [], $this->tgHeaders($leadInit))->json('data');
        $p2 = $this->postJson("/api/v1/cabinet/orders/{$o2}/pay", [], $this->tgHeaders($leadInit))->json('data');
        FakeTonPayGateway::fakePay($p1['memo'], $p1['amount_cents']);
        FakeTonPayGateway::fakePay($p2['memo'], $p2['amount_cents']);

        // Оплата первого → промоушн лида в участника.
        $this->postJson("/api/v1/cabinet/payments/{$p1['payment_id']}/check", [], $this->tgHeaders($leadInit))
            ->assertOk();
        $member = Member::where('telegram_id', 701)->first();
        $this->assertNotNull($member);
        $this->assertDatabaseHas('orders', ['id' => $o2, 'member_id' => $member->id, 'lead_id' => null]);

        // Оплата второго (теперь идентичность = участник) исполняется без осиротения.
        $check2 = $this->postJson("/api/v1/cabinet/payments/{$p2['payment_id']}/check", [], $this->tgHeaders($leadInit))
            ->assertOk();
        $this->assertSame('paid', $check2->json('data.payment_status'));
        $this->assertDatabaseHas('orders', ['id' => $o2, 'status' => 'paid']);
    }

    public function testExpireDueRemovesExpiredButKeepsPendingPaymentLeads(): void
    {
        [, $ref] = $this->registerTg(100);
        $this->makeLead(101, $ref); // будет просрочен и удалён
        $this->makeLead(102, $ref); // просрочен, но с pending-платежом → НЕ удаляем
        Lead::whereIn('telegram_id', [101, 102])->update(['expires_at' => now()->subDay()]);

        Payment::query()->create([
            'order_id' => null,
            'member_id' => null,
            'lead_id' => Lead::where('telegram_id', 102)->value('id'),
            'provider' => 'ton_pay',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => 9000,
            'currency' => 'USDT',
            'status' => Payment::STATUS_PENDING,
            'external_ref' => 'pay:999',
        ]);

        $removed = app(LeadService::class)->expireDue();

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('leads', ['telegram_id' => 101]);
        $this->assertDatabaseHas('leads', ['telegram_id' => 102]);
    }

    public function testExpireDueKeepsLeadWithExpiredPayment(): void
    {
        // G5: у просроченного лида платёж уже expired (TTL съел pending при недоступном
        // индексаторе), но деньги могли прийти on-chain. Удалять лида нельзя — иначе
        // FK nullOnDelete осиротит заказ/платёж и recheck не сможет активировать.
        [, $ref] = $this->registerTg(100);
        $this->makeLead(103, $ref);
        $this->makeLead(104, $ref); // без платежа → удаляем (контроль)
        Lead::whereIn('telegram_id', [103, 104])->update(['expires_at' => now()->subDay()]);

        Payment::query()->create([
            'order_id' => null,
            'member_id' => null,
            'lead_id' => Lead::where('telegram_id', 103)->value('id'),
            'provider' => 'ton_pay',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => 9000,
            'currency' => 'USDT',
            'status' => Payment::STATUS_EXPIRED,
            'external_ref' => 'pay:5001',
        ]);

        $removed = app(LeadService::class)->expireDue();

        $this->assertSame(1, $removed);
        $this->assertDatabaseHas('leads', ['telegram_id' => 103]);
        $this->assertDatabaseMissing('leads', ['telegram_id' => 104]);
    }

    public function testPersonalReferralsListedBySponsorshipNotBinary(): void
    {
        // Root лично пригласил A и B (sponsor_id=Root); оба в бинар-дереве Root.
        [$rootData, $rootRef] = $this->registerTg(900, name: 'Root');
        $this->registerTg(901, $rootRef, 'A');
        $this->registerTg(902, $rootRef, 'B');
        // C приглашён A (личный реферал A, не Root), но по спилловеру стоит в дереве Root.
        $aRef = Member::where('telegram_id', 901)->value('ref_code');
        $this->registerTg(903, $aRef, 'C');

        $list = $this->getJson('/api/v1/cabinet/personal-referrals', $this->tgHeaders($rootData))
            ->assertOk()->json('data');
        $names = array_column($list, 'name');

        $this->assertCount(2, $list); // только A и B (личные Root), НЕ C
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
        $this->assertNotContains('C', $names);

        // Счётчик в профиле = числу личных рефералов (не ≤2 бинар-ноги).
        $me = $this->getJson('/api/v1/cabinet/me', $this->tgHeaders($rootData))->assertOk();
        $this->assertSame(2, $me->json('data.member.personal_count'));
    }
}
