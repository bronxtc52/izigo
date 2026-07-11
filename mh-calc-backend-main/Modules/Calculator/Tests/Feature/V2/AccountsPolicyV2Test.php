<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyVersionService;
use Modules\Calculator\V2\Services\Wallet\AccountsPolicyV2;
use Modules\Calculator\V2\Services\Wallet\Exceptions\OsOrderLimitExceededException;
use Modules\Calculator\V2\Services\Wallet\OrderAccountPaymentService;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * Ревью W1 MF-5: параметры accounts.* (лимит ОС на оплату заказа, сроки лотов)
 * читаются из АКТИВНОЙ PolicyV2 (контракт T01), а не из хардкода 7000bp/365д.
 * Хардкод остаётся только fail-safe дефолтом (канон — DefaultPolicyConfig),
 * когда активной версии политики нет.
 */
class AccountsPolicyV2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-07-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Активировать версию с изменёнными accounts.* (ОС ≤50%, лоты 30/60 дней). */
    private function activateCustomAccountsPolicy(): void
    {
        $doc = DefaultPolicyConfig::doc();
        $doc['accounts']['os']['max_order_payment_share_bp'] = 5000;
        $doc['accounts']['os']['lot_lifetime_days'] = 30;
        $doc['accounts']['bs']['lot_lifetime_days'] = 60;

        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-usd-accounts-test', $doc, null);
        $service->activate($draft->id, null, Carbon::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);
    }

    private function member(): Member
    {
        return app(MemberService::class)->registerTelegram(random_int(10000, 99999999), 'P', null);
    }

    public function testReadsAccountsRulesFromActivePolicy(): void
    {
        $this->activateCustomAccountsPolicy();
        $policy = app(AccountsPolicyV2::class);

        $this->assertSame(5000, $policy->osOrderPaymentMaxShareBp(now()), 'MF-5: лимит ОС должен идти из активной политики');
        $this->assertSame(30, $policy->osLotLifetimeDays(now()), 'MF-5: срок ОС-лота должен идти из активной политики');
        $this->assertSame(60, $policy->bsLotLifetimeDays(now()), 'MF-5: срок БС-лота должен идти из активной политики');
    }

    public function testFallsBackToGateADefaultsWithoutActivePolicy(): void
    {
        $policy = app(AccountsPolicyV2::class);

        $this->assertSame(7000, $policy->osOrderPaymentMaxShareBp(now()));
        $this->assertSame(365, $policy->osLotLifetimeDays(now()));
        $this->assertSame(365, $policy->bsLotLifetimeDays(now()));
    }

    public function testReserveEnforcesPolicyOsShareLimit(): void
    {
        $this->activateCustomAccountsPolicy(); // ОС ≤50%
        $member = $this->member();
        app(WalletAccountsV2Service::class)->credit($member->id, 'os', 10000, "v2:t:os:{$member->id}", now()->addDays(365));
        $order = Order::query()->create([
            'member_id' => $member->id,
            'package_id' => 1,
            'total_usdt_cents' => 10000,
            'total_pv' => 90,
            'status' => Order::STATUS_PENDING_PAYMENT,
        ]);

        $this->expectException(OsOrderLimitExceededException::class);
        app(OrderAccountPaymentService::class)->reserve($order, 5001, 0);
    }

    public function testNsTransferLotLifetimeFromPolicy(): void
    {
        $this->activateCustomAccountsPolicy(); // ОС-лот 30 дней
        $member = $this->member();
        $wallet = app(WalletAccountsV2Service::class);
        $wallet->credit($member->id, 'ns', 10000, "v2:t:ns:{$member->id}");

        $wallet->executeForCalibratedMonth('2026-07', 10000);

        $lot = \Modules\Calculator\Models\V2\WalletLotV2::query()
            ->where('member_id', $member->id)->where('account', 'os')->sole();
        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $lot->expires_at->toDateString(),
            'MF-5: срок ОС-лота перевода НС→ОС должен браться из активной политики'
        );
    }
}
