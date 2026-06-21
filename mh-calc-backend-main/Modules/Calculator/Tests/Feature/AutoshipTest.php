<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Product;
use Modules\Calculator\Services\AutoshipService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Autoship (Фаза 4, S6 / US-6, US-7): списание с внутреннего баланса + ре-покупка;
 * retry д.3/7/14 при нехватке средств; пауза после исчерпания повторов.
 */
class AutoshipTest extends TestCase
{
    use RefreshDatabase;

    private AutoshipService $autoship;

    protected function setUp(): void
    {
        parent::setUp();
        $this->autoship = app(AutoshipService::class);
    }

    private function member(int $tg): Member
    {
        return app(MemberService::class)->registerTelegram($tg, "U{$tg}", null);
    }

    private function bronze(): Product
    {
        return Product::query()->create([
            'name' => 'Bronze', 'price_usdt_cents' => 9000, 'pv' => 90,
            'package_id' => 1, 'sku' => 'TARIFF-BRONZE', 'is_active' => true, 'sort' => 1,
        ]);
    }

    private function seedBalance(int $memberId, int $cents): void
    {
        DB::transaction(fn () => app(LedgerService::class)->deposit($memberId, $cents, "seed:m{$memberId}"));
    }

    private function dueSub(Member $m, Product $p, int $retryStage = 0): AutoshipSubscription
    {
        return AutoshipSubscription::query()->create([
            'member_id' => $m->id,
            'product_id' => $p->id,
            'package_id' => $p->package_id,
            'interval_days' => 30,
            'next_charge_at' => now()->subDay(),
            'status' => AutoshipSubscription::STATUS_ACTIVE,
            'retry_stage' => $retryStage,
        ]);
    }

    public function testChargesBalanceAndReactivates(): void
    {
        $m = $this->member(800);
        $p = $this->bronze();
        $this->seedBalance($m->id, 20000);
        $sub = $this->dueSub($m, $p);

        $summary = $this->autoship->runDue(now());

        $this->assertSame(1, $summary['charged']);
        $this->assertSame(11000, MemberWallet::where('member_id', $m->id)->first()->available_cents);
        $this->assertSame(1, Order::where('member_id', $m->id)->where('status', Order::STATUS_PAID)->count());

        $sub->refresh();
        $this->assertSame(0, $sub->retry_stage);
        $this->assertTrue($sub->next_charge_at->isFuture());
    }

    public function testRetriesOnInsufficientFunds(): void
    {
        $m = $this->member(810);
        $p = $this->bronze();
        // Баланс пуст.
        $sub = $this->dueSub($m, $p);

        $summary = $this->autoship->runDue(now());

        $this->assertSame(1, $summary['retried']);
        $this->assertSame(0, Order::where('member_id', $m->id)->count());

        $sub->refresh();
        $this->assertSame(3, $sub->retry_stage); // первая ступень
        $this->assertSame(AutoshipSubscription::STATUS_ACTIVE, $sub->status);
        $this->assertTrue($sub->next_charge_at->isFuture());
    }

    public function testRetryLadderAdvances(): void
    {
        $m = $this->member(820);
        $p = $this->bronze();
        $sub = $this->dueSub($m, $p, retryStage: 3);
        $sub->next_charge_at = now()->subDay();
        $sub->save();

        $this->autoship->runDue(now());
        $this->assertSame(7, $sub->refresh()->retry_stage); // 3 → 7
    }

    public function testPausesAfterRetriesExhausted(): void
    {
        $m = $this->member(830);
        $p = $this->bronze();
        $sub = $this->dueSub($m, $p, retryStage: 14); // последняя ступень исчерпана

        $summary = $this->autoship->runDue(now());

        $this->assertSame(1, $summary['paused']);
        $this->assertSame(AutoshipSubscription::STATUS_PAUSED, $sub->refresh()->status);
    }

    public function testChargeIsScopedAndNotDoubleSpentSameRun(): void
    {
        $m = $this->member(840);
        $p = $this->bronze();
        $this->seedBalance($m->id, 9000); // ровно на одно списание
        $this->dueSub($m, $p);

        $this->autoship->runDue(now());
        // Повторный прогон в тот же момент: подписка уже сдвинута в будущее → не списывается снова.
        $this->autoship->runDue(now());

        $this->assertSame(0, MemberWallet::where('member_id', $m->id)->first()->available_cents);
        $this->assertSame(1, Order::where('member_id', $m->id)->count());
    }
}
