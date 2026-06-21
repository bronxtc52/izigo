<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\Services\MemberService;
use Tests\TestCase;

/**
 * Привязка ledger к активации: дельта снимка дохода (new − prev) уходит в кошелёк
 * иммутабельными проводками. Кошелёк сходится с member_earnings, повтор идемпотентен.
 * Пакет Bronze = id 1 = 90 PV (referral 10% = $9, binary 5% от пары).
 */
class AccrualLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const BRONZE = 1;

    private int $tg = 5000;

    /** Root R с личниками A (left) и B (right). */
    private function buildTriangle(): array
    {
        $svc = app(MemberService::class);
        $root = $svc->registerTelegram($this->tg++, 'R', null);
        $a = $svc->registerTelegram($this->tg++, 'A', null, $root->ref_code);
        $b = $svc->registerTelegram($this->tg++, 'B', null, $root->ref_code);

        return [$root, $a, $b];
    }

    private function available(int $memberId): int
    {
        return (int) (MemberWallet::where('member_id', $memberId)->value('available_cents') ?? 0);
    }

    public function testReferralAccrualCreditsSponsorWallet(): void
    {
        [$root, $a] = $this->buildTriangle();
        $svc = app(ActivationService::class);
        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');

        // Referral $9 спонсору → 900 центов в доступном балансе.
        $this->assertSame(900, $this->available($root->id));
        // Кошелёк сходится со снимком дохода.
        $earnTotal = (string) MemberEarning::where('member_id', $root->id)->value('total');
        $this->assertSame(900, $this->decimalToCents($earnTotal));
    }

    public function testWalletAccumulatesReferralAndBinaryAcrossActivations(): void
    {
        [$root, $a, $b] = $this->buildTriangle();
        $svc = app(ActivationService::class);
        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $svc->activate($b->id, self::BRONZE, 'evt-B');

        // 2×referral $18 + binary $4.5 = $22.5 → 2250 центов (дельты сложились корректно).
        $this->assertSame(2250, $this->available($root->id));
    }

    public function testReactivationDoesNotDoubleAccrue(): void
    {
        [$root, $a] = $this->buildTriangle();
        $svc = app(ActivationService::class);
        $svc->activate($root->id, self::BRONZE, 'evt-R');
        $svc->activate($a->id, self::BRONZE, 'evt-A');
        $before = $this->available($root->id);
        $accrualGroups = LedgerEntry::where('source_type', 'accrual')
            ->whereNotNull('idempotency_key')->count();

        $svc->activate($a->id, self::BRONZE, 'evt-A'); // повтор того же ключа

        $this->assertSame($before, $this->available($root->id));
        $this->assertSame(
            $accrualGroups,
            LedgerEntry::where('source_type', 'accrual')->whereNotNull('idempotency_key')->count(),
        );
    }

    private function decimalToCents(string $value): int
    {
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '0');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return (int) $int * 100 + (int) $frac;
    }
}
