<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Exceptions\InsufficientFundsException;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Расширение ledger для commerce (Фаза 4, S2): deposit (пополнение баланса извне) и
 * charge (списание под покупку/autoship). Проверяем баланс проводок, кэш кошелька,
 * guard по нехватке средств и идемпотентность.
 */
class LedgerCommerceTest extends TestCase
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

    private function assertAllTransactionsBalanced(): void
    {
        foreach (LedgerEntry::query()->pluck('tx_id')->unique() as $txId) {
            $legs = LedgerEntry::where('tx_id', $txId)->get();
            $debit = $legs->where('direction', LedgerService::DR)->sum('amount_cents');
            $credit = $legs->where('direction', LedgerService::CR)->sum('amount_cents');
            $this->assertSame($debit, $credit, "tx {$txId} unbalanced");
        }
    }

    public function testDepositCreditsAvailable(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->deposit($m->id, 5000, "deposit:p1:m{$m->id}"));

        $this->assertSame(5000, $this->wallet($m->id)->available_cents);
        $this->assertSame(2, LedgerEntry::where('source_type', 'deposit')->count()); // Dr deposits + Cr available
        $this->assertAllTransactionsBalanced();
    }

    public function testDepositIsIdempotent(): void
    {
        $m = $this->member('A');
        $key = "deposit:p1:m{$m->id}";
        $this->tx(fn () => $this->ledger->deposit($m->id, 5000, $key));
        $this->tx(fn () => $this->ledger->deposit($m->id, 5000, $key)); // повтор того же ключа

        $this->assertSame(5000, $this->wallet($m->id)->available_cents);
        $this->assertSame(1, LedgerEntry::where('idempotency_key', $key)->count());
    }

    public function testChargeDebitsAvailable(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->deposit($m->id, 5000, "deposit:p1:m{$m->id}"));
        $this->tx(fn () => $this->ledger->charge($m->id, 3000, "charge:o1:m{$m->id}", 1));

        $this->assertSame(2000, $this->wallet($m->id)->available_cents);
        $this->assertSame(2, LedgerEntry::where('source_type', 'purchase')->count());
        $this->assertAllTransactionsBalanced();
    }

    public function testChargeRejectsInsufficientFunds(): void
    {
        $m = $this->member('A');
        $this->tx(fn () => $this->ledger->deposit($m->id, 1000, "deposit:p1:m{$m->id}"));

        $this->expectException(InsufficientFundsException::class);
        try {
            $this->tx(fn () => $this->ledger->charge($m->id, 2000, "charge:o1:m{$m->id}", 1));
        } finally {
            // Баланс не тронут, проводок покупки нет.
            $this->assertSame(1000, $this->wallet($m->id)->available_cents);
            $this->assertSame(0, LedgerEntry::where('source_type', 'purchase')->count());
        }
    }

    public function testChargeIsIdempotent(): void
    {
        $m = $this->member('A');
        $key = "charge:o1:m{$m->id}";
        $this->tx(fn () => $this->ledger->deposit($m->id, 5000, "deposit:p1:m{$m->id}"));
        $this->tx(fn () => $this->ledger->charge($m->id, 2000, $key, 1));
        $this->tx(fn () => $this->ledger->charge($m->id, 2000, $key, 1)); // повтор того же ключа

        $this->assertSame(3000, $this->wallet($m->id)->available_cents);
        $this->assertSame(1, LedgerEntry::where('idempotency_key', $key)->count());
    }
}
