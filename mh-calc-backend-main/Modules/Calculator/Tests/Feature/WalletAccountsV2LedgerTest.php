<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotConsumptionV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\Exceptions\InsufficientAccountBalanceException;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;
use Tests\TestCase;

/**
 * mh-full-plan T02 (деньги): субсчета ОС/НС/БС поверх ledger — постер двойной записи,
 * credit/debit контракта LedgerV2, лоты (earliest-expiry-first, MF-9 null-expiry),
 * V2-цикл вывода (только ОС) и инвариант «кэш == свёртка ledger_entries».
 */
class WalletAccountsV2LedgerTest extends TestCase
{
    use RefreshDatabase;

    private WalletAccountsV2Service $wallet;
    private LedgerPostingV2Service $poster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = app(WalletAccountsV2Service::class);
        $this->poster = app(LedgerPostingV2Service::class);
    }

    private function member(string $name = 'A'): Member
    {
        return app(MemberService::class)->registerTelegram(random_int(10000, 99999999), $name, null);
    }

    private function account(int $memberId): MemberAccountV2
    {
        return MemberAccountV2::query()->where('member_id', $memberId)->firstOrFail();
    }

    private function assertAllTransactionsBalanced(): void
    {
        foreach (LedgerEntry::query()->pluck('tx_id')->unique() as $txId) {
            $legs = LedgerEntry::where('tx_id', $txId)->get();
            $debit = $legs->where('direction', 'debit')->sum('amount_cents');
            $credit = $legs->where('direction', 'credit')->sum('amount_cents');
            $this->assertSame($debit, $credit, "tx {$txId} unbalanced");
        }
    }

    /** Инвариант кэша: каждая колонка v2_member_accounts = Σcredit − Σdebit account_type. */
    private function assertCacheMatchesLedger(int $memberId): void
    {
        $fold = function (string $accountType) use ($memberId): int {
            $cr = (int) LedgerEntry::query()->where('member_id', $memberId)
                ->where('account_type', $accountType)->where('direction', 'credit')->sum('amount_cents');
            $dr = (int) LedgerEntry::query()->where('member_id', $memberId)
                ->where('account_type', $accountType)->where('direction', 'debit')->sum('amount_cents');

            return $cr - $dr;
        };
        $a = $this->account($memberId);
        $this->assertSame($fold(LedgerPostingV2Service::ACC_OS_AVAILABLE), $a->os_available_cents, 'os_available drift');
        $this->assertSame($fold(LedgerPostingV2Service::ACC_OS_HELD), $a->os_held_cents, 'os_held drift');
        $this->assertSame($fold(LedgerPostingV2Service::ACC_NS), $a->ns_cents, 'ns drift');
        $this->assertSame($fold(LedgerPostingV2Service::ACC_BS_AVAILABLE), $a->bs_available_cents, 'bs_available drift');
        $this->assertSame($fold(LedgerPostingV2Service::ACC_BS_HELD), $a->bs_held_cents, 'bs_held drift');
    }

    // ------------------------------------------------------------------
    // LedgerPostingV2Service — механика двойной записи
    // ------------------------------------------------------------------

    public function testUnbalancedPostThrows(): void
    {
        $m = $this->member();
        $this->expectException(\DomainException::class);
        $this->poster->post([
            $this->poster->leg($m->id, LedgerPostingV2Service::ACC_OS_AVAILABLE, 'credit', 100),
            $this->poster->leg(null, LedgerPostingV2Service::ACC_EXPIRED_BALANCE, 'debit', 99),
        ], 'bonus_v2', null, 'v2:test:unbalanced');
    }

    public function testNonPositiveLegThrows(): void
    {
        $m = $this->member();
        $this->expectException(\DomainException::class);
        $this->poster->post([
            $this->poster->leg($m->id, LedgerPostingV2Service::ACC_OS_AVAILABLE, 'credit', 0),
            $this->poster->leg(null, LedgerPostingV2Service::ACC_EXPIRED_BALANCE, 'debit', 0),
        ], 'bonus_v2', null, 'v2:test:zero');
    }

    // ------------------------------------------------------------------
    // credit()
    // ------------------------------------------------------------------

    public function testCreditOsCreatesLotWithExpiry(): void
    {
        $m = $this->member();
        $expires = now()->addDays(365);
        $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_OS, 10000, "v2:t:os:{$m->id}", $expires, 'referral_v2', 77);

        $this->assertSame(10000, $this->account($m->id)->os_available_cents);
        $lot = WalletLotV2::query()->where('member_id', $m->id)->sole();
        $this->assertSame('os', $lot->account);
        $this->assertSame(10000, $lot->available_cents);
        $this->assertSame('referral_v2', $lot->source_type);
        $this->assertSame($expires->toDateString(), $lot->expires_at->toDateString());
        $this->assertAllTransactionsBalanced();
        $this->assertCacheMatchesLedger($m->id);
    }

    public function testCreditNsCreatesNoLot(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_NS, 5000, "v2:t:ns:{$m->id}");

        $this->assertSame(5000, $this->account($m->id)->ns_cents);
        $this->assertSame(0, WalletLotV2::query()->where('member_id', $m->id)->count());
        $this->assertCacheMatchesLedger($m->id);
    }

    public function testCreditBsWithNullExpiryLotNeverExpires(): void
    {
        // MF-9: award-лоты БС кредитуются с null и НЕ сгорают.
        $m = $this->member();
        $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_BS, 20000, "v2:t:award:{$m->id}", null, 'award_v2');

        $lot = WalletLotV2::query()->where('member_id', $m->id)->sole();
        $this->assertNull($lot->expires_at);

        // Даже спустя «10 лет» лот не сгорает.
        $processed = $this->wallet->expireLots(now()->addYears(10));
        $this->assertSame(0, $processed);
        $this->assertSame(20000, $this->account($m->id)->bs_available_cents);
    }

    public function testCreditIsIdempotent(): void
    {
        $m = $this->member();
        $key = "v2:t:idem:{$m->id}";
        $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_OS, 1000, $key, now()->addDays(365));
        $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_OS, 1000, $key, now()->addDays(365));

        $this->assertSame(1000, $this->account($m->id)->os_available_cents);
        $this->assertSame(1, WalletLotV2::query()->where('member_id', $m->id)->count());
        $this->assertSame(1, LedgerEntry::where('idempotency_key', $key)->count());
    }

    public function testCreditRejectsNonPositiveAndUnknownSubaccount(): void
    {
        $m = $this->member();
        try {
            $this->wallet->credit($m->id, LedgerV2::SUBACCOUNT_OS, 0, 'v2:t:zero');
            $this->fail('Ожидался DomainException на нулевую сумму');
        } catch (\DomainException) {
        }
        $this->expectException(\DomainException::class);
        $this->wallet->credit($m->id, 'xx', 100, 'v2:t:bad-acct');
    }

    public function testContainerBindingsResolveContracts(): void
    {
        $this->assertInstanceOf(WalletAccountsV2Service::class, app(LedgerV2::class));
        $this->assertInstanceOf(WalletAccountsV2Service::class, app(\Modules\Calculator\V2\Contracts\NsToOsTransfer::class));
    }

    // ------------------------------------------------------------------
    // debit() — earliest-expiry-first
    // ------------------------------------------------------------------

    public function testDebitConsumesEarliestExpiryFirst(): void
    {
        $m = $this->member();
        // Три лота с перепутанным порядком создания: сгорает раньше — тратится раньше.
        $this->wallet->credit($m->id, 'os', 300, 'v2:t:l3', now()->addDays(300));
        $this->wallet->credit($m->id, 'os', 100, 'v2:t:l1', now()->addDays(100));
        $this->wallet->credit($m->id, 'os', 200, 'v2:t:l2', now()->addDays(200));

        $this->wallet->debit($m->id, 'os', 150, "v2:t:debit:{$m->id}");

        $l1 = WalletLotV2::query()->where('idempotency_key', 'v2:t:l1')->sole();
        $l2 = WalletLotV2::query()->where('idempotency_key', 'v2:t:l2')->sole();
        $l3 = WalletLotV2::query()->where('idempotency_key', 'v2:t:l3')->sole();

        $this->assertSame(0, $l1->available_cents);   // самый ранний — выбран до нуля
        $this->assertSame(WalletLotV2::STATUS_EXHAUSTED, $l1->status);
        $this->assertSame(150, $l2->available_cents); // частично
        $this->assertSame(300, $l3->available_cents); // не тронут
        $this->assertSame(450, $this->account($m->id)->os_available_cents);

        // Трассировка полна: сумма consumption-строк = сумме операции.
        $consumed = WalletLotConsumptionV2::query()->where('reason', 'debit')->sum('amount_cents');
        $this->assertSame(150, (int) $consumed);
        $this->assertCacheMatchesLedger($m->id);
    }

    public function testDebitNullExpiryLotsConsumedLast(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'bs', 100, 'v2:t:noexp', null);
        $this->wallet->credit($m->id, 'bs', 100, 'v2:t:exp', now()->addDays(30));

        $this->wallet->debit($m->id, 'bs', 100, "v2:t:debit-bs:{$m->id}");

        $this->assertSame(100, WalletLotV2::query()->where('idempotency_key', 'v2:t:noexp')->sole()->available_cents);
        $this->assertSame(0, WalletLotV2::query()->where('idempotency_key', 'v2:t:exp')->sole()->available_cents);
    }

    public function testDebitInsufficientThrowsAndChangesNothing(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 1000, "v2:t:c:{$m->id}", now()->addDays(365));

        try {
            $this->wallet->debit($m->id, 'os', 2000, "v2:t:over:{$m->id}");
            $this->fail('Ожидался InsufficientAccountBalanceException');
        } catch (InsufficientAccountBalanceException) {
        }

        $this->assertSame(1000, $this->account($m->id)->os_available_cents);
        $this->assertSame(0, LedgerEntry::where('source_type', 'acct_charge')->count());
        $this->assertCacheMatchesLedger($m->id);
    }

    public function testDebitIsIdempotent(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 1000, "v2:t:c:{$m->id}", now()->addDays(365));
        $key = "v2:t:d:{$m->id}";
        $this->wallet->debit($m->id, 'os', 300, $key);
        $this->wallet->debit($m->id, 'os', 300, $key);

        $this->assertSame(700, $this->account($m->id)->os_available_cents);
        $this->assertSame(1, LedgerEntry::where('idempotency_key', $key)->count());
    }

    // ------------------------------------------------------------------
    // Цикл вывода V2 — только ОС
    // ------------------------------------------------------------------

    private function withdrawal(Member $m, int $cents): WithdrawalRequest
    {
        return WithdrawalRequest::query()->create([
            'member_id' => $m->id,
            'amount_cents' => $cents,
            'payout_details' => 'UQTestAddress',
            'status' => WithdrawalRequest::STATUS_REQUESTED,
            'requested_at' => now(),
        ]);
    }

    public function testWithdrawalHoldReleasePaidCycle(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 5000, "v2:t:c:{$m->id}", now()->addDays(365));

        $w = $this->withdrawal($m, 3000);
        $this->wallet->holdForWithdrawal($w);
        $a = $this->account($m->id);
        $this->assertSame(2000, $a->os_available_cents);
        $this->assertSame(3000, $a->os_held_cents);
        $this->assertSame(2000, WalletLotV2::query()->where('member_id', $m->id)->sole()->available_cents);

        // Отмена: средства вернулись в тот же лот.
        $this->wallet->releaseWithdrawalHold($w);
        $a = $this->account($m->id);
        $this->assertSame(5000, $a->os_available_cents);
        $this->assertSame(0, $a->os_held_cents);
        $this->assertSame(5000, WalletLotV2::query()->where('member_id', $m->id)->sole()->available_cents);

        // Повторный hold и выплата.
        $w2 = $this->withdrawal($m, 1000);
        $this->wallet->holdForWithdrawal($w2);
        $this->wallet->markWithdrawalPaid($w2);
        $a = $this->account($m->id);
        $this->assertSame(4000, $a->os_available_cents);
        $this->assertSame(0, $a->os_held_cents);
        $this->assertSame(1000, (int) LedgerEntry::query()
            ->where('account_type', 'company_payouts_paid')
            ->where('source_type', 'withdrawal_v2')->sum('amount_cents'));

        $this->assertAllTransactionsBalanced();
        $this->assertCacheMatchesLedger($m->id);
    }

    public function testWithdrawalHoldRejectsWhenOnlyBsFunds(): void
    {
        // БС невыводим: hold смотрит ТОЛЬКО на os_available.
        $m = $this->member();
        $this->wallet->credit($m->id, 'bs', 10000, "v2:t:bs:{$m->id}", null);
        $this->wallet->credit($m->id, 'ns', 10000, "v2:t:ns:{$m->id}");

        $w = $this->withdrawal($m, 1000);
        $this->expectException(InsufficientAccountBalanceException::class);
        $this->wallet->holdForWithdrawal($w);
    }

    public function testWithdrawalCycleIsIdempotent(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 5000, "v2:t:c:{$m->id}", now()->addDays(365));
        $w = $this->withdrawal($m, 2000);

        $this->wallet->holdForWithdrawal($w);
        $this->wallet->holdForWithdrawal($w); // повтор — no-op
        $this->assertSame(3000, $this->account($m->id)->os_available_cents);

        $this->wallet->markWithdrawalPaid($w);
        $this->wallet->markWithdrawalPaid($w); // повтор — no-op
        $this->assertSame(0, $this->account($m->id)->os_held_cents);
        $this->assertCacheMatchesLedger($m->id);
    }

    // ------------------------------------------------------------------
    // Совместимость с V1
    // ------------------------------------------------------------------

    public function testV1WalletUntouchedByV2Operations(): void
    {
        $m = $this->member();
        $this->wallet->credit($m->id, 'os', 9000, "v2:t:c1:{$m->id}", now()->addDays(365));
        $this->wallet->credit($m->id, 'ns', 4000, "v2:t:c2:{$m->id}");
        $this->wallet->debit($m->id, 'os', 1000, "v2:t:d1:{$m->id}");

        // V1-кошелёк не создан и не изменён ни одной V2-операцией.
        $this->assertNull(MemberWallet::query()->where('member_id', $m->id)->first());
        // V1-типы счетов участника не затронуты.
        $this->assertSame(0, LedgerEntry::query()->where('member_id', $m->id)
            ->whereIn('account_type', ['member_available', 'member_held', 'member_clawback_debt'])->count());
    }
}
