<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotConsumptionV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * mh-full-plan T02 (деньги): сгорание кредит-лотов. ОС-остаток → БС-лот с origin_lot_id
 * и годовым сроком с даты переноса (BR-ACC-004); БС-остаток → forfeit в
 * company_expired_balance. Джоб идемпотентен; null-expiry пропускается (MF-9).
 */
class WalletLotsExpireV2Test extends TestCase
{
    use RefreshDatabase;

    private WalletAccountsV2Service $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletAccountsV2Service::class);
    }

    private function member(): Member
    {
        return app(MemberService::class)->registerTelegram(random_int(10000, 99999999), 'L', null);
    }

    private function account(int $memberId): MemberAccountV2
    {
        return MemberAccountV2::query()->where('member_id', $memberId)->firstOrFail();
    }

    public function testExpiredOsLotTransfersRemainderToBs(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 10000, "v2:t:os:{$m->id}", now()->addDays(365));
        // Часть потрачена — переносится ОСТАТОК.
        $this->wallet->debit($m->id, 'os', 3000, "v2:t:spend:{$m->id}");

        $asOf = now()->addDays(366);
        $processed = $this->wallet->expireLots($asOf);
        $this->assertSame(1, $processed);

        $a = $this->account($m->id);
        $this->assertSame(0, $a->os_available_cents);
        $this->assertSame(7000, $a->bs_available_cents);

        $osLot = WalletLotV2::query()->where('member_id', $m->id)->where('account', 'os')->sole();
        $this->assertSame(WalletLotV2::STATUS_TRANSFERRED, $osLot->status);
        $this->assertSame(0, $osLot->available_cents);

        // Новый БС-лот: origin_lot_id + годовой срок с даты переноса.
        $bsLot = WalletLotV2::query()->where('member_id', $m->id)->where('account', 'bs')->sole();
        $this->assertSame($osLot->id, $bsLot->origin_lot_id);
        $this->assertSame(7000, $bsLot->available_cents);
        $this->assertSame($asOf->copy()->addDays(365)->toDateString(), $bsLot->expires_at->toDateString());

        // Трассировка сгорания.
        $this->assertSame(7000, (int) WalletLotConsumptionV2::query()
            ->where('lot_id', $osLot->id)->where('reason', 'expiry_transfer')->sum('amount_cents'));
    }

    public function testExpiredBsLotForfeitsToCompany(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'bs', 5000, "v2:t:bs:{$m->id}", now()->addDays(365));

        $this->wallet->expireLots(now()->addDays(400));

        $this->assertSame(0, $this->account($m->id)->bs_available_cents);
        $lot = WalletLotV2::query()->where('member_id', $m->id)->sole();
        $this->assertSame(WalletLotV2::STATUS_EXPIRED, $lot->status);
        $this->assertSame(5000, (int) LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_EXPIRED_BALANCE)
            ->where('direction', 'credit')->sum('amount_cents'));
    }

    public function testDoubleRunIsIdempotent(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 10000, "v2:t:os:{$m->id}", now()->addDays(10));

        $asOf = now()->addDays(11);
        $this->assertSame(1, $this->wallet->expireLots($asOf));
        // Второй прогон: исходный ОС-лот уже transferred; новый БС-лот ещё не истёк.
        $this->assertSame(0, $this->wallet->expireLots($asOf));

        $this->assertSame(10000, $this->account($m->id)->bs_available_cents);
        // Ровно одна группа проводок сгорания (2 ноги) — повтор не задвоил.
        $this->assertSame(2, LedgerEntry::query()->where('source_type', 'lot_expiry')->count());
    }

    public function testChainOsToBsToForfeit(): void
    {
        // Полный жизненный цикл: ОС (год) → БС (ещё год) → forfeit.
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 1000, "v2:t:os:{$m->id}", now()->addDays(365));

        $this->wallet->expireLots(now()->addDays(366)); // ОС → БС
        $this->wallet->expireLots(now()->addDays(366 + 366)); // БС → forfeit

        $a = $this->account($m->id);
        $this->assertSame(0, $a->os_available_cents);
        $this->assertSame(0, $a->bs_available_cents);
        $this->assertSame(1000, (int) LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_EXPIRED_BALANCE)
            ->where('direction', 'credit')->sum('amount_cents'));
    }

    public function testNonExpiredAndFullyConsumedLotsUntouched(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 1000, "v2:t:fresh:{$m->id}", now()->addDays(365));
        $this->wallet->credit($m->id, 'os', 500, "v2:t:used:{$m->id}", now()->addDays(10));
        // Второй лот выбираем под ноль (первым сгорающим тратится именно он).
        $this->wallet->debit($m->id, 'os', 500, "v2:t:spend:{$m->id}");

        $processed = $this->wallet->expireLots(now()->addDays(11));
        // Потреблённый до нуля лот не порождает проводок (нечего переносить).
        $this->assertSame(0, $processed);
        $this->assertSame(WalletLotV2::STATUS_EXHAUSTED,
            WalletLotV2::query()->where('idempotency_key', "v2:t:used:{$m->id}")->sole()->status);
        // Живой лот не тронут.
        $this->assertSame(1000, $this->account($m->id)->os_available_cents);
        $this->assertSame(0, $this->account($m->id)->bs_available_cents);
    }
}
