<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\WithdrawalRequest;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Журнал двойной записи: баланс проводок, кэш кошелька, clawback, холд/возврат/выплата,
 * идемпотентность и инвариант «кэш = свёртка ledger». Деньги — целые центы.
 */
class LedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    private function member(string $name): Member
    {
        return app(MemberService::class)->registerTelegram(random_int(10000, 99999999), $name, null);
    }

    private function tx(callable $fn): void
    {
        DB::transaction($fn);
    }

    private function wallet(int $memberId): MemberWallet
    {
        return MemberWallet::where('member_id', $memberId)->firstOrFail();
    }

    /** Доступный = Σcredit − Σdebit по member_available и т.п. (инвариант кэша). */
    private function fold(int $memberId, string $account, bool $debitPositive): int
    {
        $debit = (int) LedgerEntry::where('member_id', $memberId)->where('account_type', $account)
            ->where('direction', LedgerService::DR)->sum('amount_cents');
        $credit = (int) LedgerEntry::where('member_id', $memberId)->where('account_type', $account)
            ->where('direction', LedgerService::CR)->sum('amount_cents');

        return $debitPositive ? $debit - $credit : $credit - $debit;
    }

    private function assertWalletMatchesLedger(int $memberId): void
    {
        $w = $this->wallet($memberId);
        $this->assertSame($this->fold($memberId, LedgerService::ACC_AVAILABLE, false), $w->available_cents, 'available');
        $this->assertSame($this->fold($memberId, LedgerService::ACC_HELD, false), $w->held_cents, 'held');
        $this->assertSame($this->fold($memberId, LedgerService::ACC_CLAWBACK_DEBT, true), $w->clawback_debt_cents, 'debt');
    }

    /** Каждая операция сбалансирована: Σdebit = Σcredit в пределах tx_id. */
    private function assertAllTransactionsBalanced(): void
    {
        foreach (LedgerEntry::query()->pluck('tx_id')->unique() as $txId) {
            $legs = LedgerEntry::where('tx_id', $txId)->get();
            $debit = $legs->where('direction', LedgerService::DR)->sum('amount_cents');
            $credit = $legs->where('direction', LedgerService::CR)->sum('amount_cents');
            $this->assertSame($debit, $credit, "tx {$txId} unbalanced");
        }
    }

    public function testAccrualCreditsAvailableAndIsBalanced(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, "accrual:ae1:m{$m->id}"));

        $this->assertSame(1000, $this->wallet($m->id)->available_cents);
        $this->assertSame(0, $this->wallet($m->id)->clawback_debt_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }

    public function testAccrualIsIdempotent(): void
    {
        $m = $this->member('A');
        $key = "accrual:ae1:m{$m->id}";
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, $key));
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, $key)); // повтор того же ключа

        $this->assertSame(1000, $this->wallet($m->id)->available_cents);
        // Ровно одна группа проводок (ключ висит на первой проводке группы).
        $this->assertSame(1, LedgerEntry::where('idempotency_key', $key)->count());
        $this->assertSame(2, LedgerEntry::where('source_type', 'accrual')->count()); // expense Dr + available Cr
    }

    public function testNegativeAccrualWithinAvailable(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, "k1:m{$m->id}"));
        $this->tx(fn () => $this->ledger->accrual($m->id, -300, 2, "k2:m{$m->id}"));

        $this->assertSame(700, $this->wallet($m->id)->available_cents);
        $this->assertSame(0, $this->wallet($m->id)->clawback_debt_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }

    public function testClawbackWhenCorrectionExceedsAvailable(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, "k1:m{$m->id}"));

        // Вывели 800 в холд: available=200, held=800.
        $w = WithdrawalRequest::create([
            'member_id' => $m->id, 'amount_cents' => 800, 'payout_details' => 'iban',
            'status' => WithdrawalRequest::STATUS_REQUESTED, 'requested_at' => now(),
        ]);
        $this->tx(fn () => $this->ledger->hold($w));

        // Коррекция вниз на 500 при available=200 → available=0, долг=300.
        $this->tx(fn () => $this->ledger->accrual($m->id, -500, 2, "k2:m{$m->id}"));

        $wallet = $this->wallet($m->id);
        $this->assertSame(0, $wallet->available_cents);
        $this->assertSame(800, $wallet->held_cents);
        $this->assertSame(300, $wallet->clawback_debt_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }

    public function testFutureAccrualPaysDebtFirst(): void
    {
        $m = $this->member('A');
        // Сразу уводим в долг: при пустом кошельке коррекция вниз на 300 → долг=300.
        $this->tx(fn () => $this->ledger->accrual($m->id, -300, 1, "k1:m{$m->id}"));
        $this->assertSame(300, $this->wallet($m->id)->clawback_debt_cents);

        // Новое начисление 500 сначала гасит долг 300, остаток 200 в available.
        $this->tx(fn () => $this->ledger->accrual($m->id, 500, 2, "k2:m{$m->id}"));

        $wallet = $this->wallet($m->id);
        $this->assertSame(0, $wallet->clawback_debt_cents);
        $this->assertSame(200, $wallet->available_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }

    public function testHoldThenReleaseReturnsFunds(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, "k1:m{$m->id}"));

        $w = WithdrawalRequest::create([
            'member_id' => $m->id, 'amount_cents' => 400, 'payout_details' => 'iban',
            'status' => WithdrawalRequest::STATUS_REQUESTED, 'requested_at' => now(),
        ]);

        $this->tx(fn () => $this->ledger->hold($w));
        $this->assertSame(600, $this->wallet($m->id)->available_cents);
        $this->assertSame(400, $this->wallet($m->id)->held_cents);

        // Возврат холда (reject/cancel) — средства возвращаются в доступные.
        $this->tx(fn () => $this->ledger->releaseHold($w));
        $this->assertSame(1000, $this->wallet($m->id)->available_cents);
        $this->assertSame(0, $this->wallet($m->id)->held_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }

    public function testHoldThenMarkPaidConsumesFunds(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->accrual($m->id, 1000, 1, "k1:m{$m->id}"));

        $w = WithdrawalRequest::create([
            'member_id' => $m->id, 'amount_cents' => 400, 'payout_details' => 'iban',
            'status' => WithdrawalRequest::STATUS_REQUESTED, 'requested_at' => now(),
        ]);

        $this->tx(fn () => $this->ledger->hold($w));
        $this->tx(fn () => $this->ledger->markPaid($w));

        // Выплачено наружу: held списан, available не возвращается.
        $this->assertSame(600, $this->wallet($m->id)->available_cents);
        $this->assertSame(0, $this->wallet($m->id)->held_cents);
        $this->assertAllTransactionsBalanced();
        $this->assertWalletMatchesLedger($m->id);
    }
}
