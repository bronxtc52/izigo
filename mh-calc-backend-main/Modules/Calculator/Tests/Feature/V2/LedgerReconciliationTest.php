<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsCutoverData;
use Modules\Calculator\V2\Services\Cutover\LedgerReconciliationService;
use Tests\TestCase;

/**
 * T15 [ДЕНЬГИ]: read-only сверка ledger (прекондишен cutover / проверка после rollback).
 * Сбалансированный ledger → ok; дрейф кэша → ok=false; после opening-миграции trial
 * balance остаётся 0 и кэши/лоты сходятся.
 */
class LedgerReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCutoverData;

    public function test_balanced_ledger_reconciles_ok(): void
    {
        $a = $this->seedMember(9001);
        $b = $this->seedMember(9002, $a);
        $this->deposit($a->id, 10000);
        $this->deposit($b->id, 5000);

        $recon = app(LedgerReconciliationService::class)->check();

        $this->assertTrue($recon['ok']);
        $this->assertSame(0, $recon['trial_balance']['delta']);
        $this->assertSame([], $recon['unbalanced_tx']);
        $this->assertSame([], $recon['cache_drift']);
    }

    public function test_detects_wallet_cache_drift(): void
    {
        $a = $this->seedMember(9001);
        $this->deposit($a->id, 10000);
        MemberWallet::query()->where('member_id', $a->id)->update(['available_cents' => 12000]);

        $recon = app(LedgerReconciliationService::class)->check();

        $this->assertFalse($recon['ok']);
        $this->assertNotEmpty($recon['cache_drift']);
    }

    public function test_reconciles_after_opening_migration(): void
    {
        $a = $this->seedMember(9001);
        $b = $this->seedMember(9002, $a);
        $this->deposit($a->id, 10000);
        $this->deposit($b->id, 5000);
        $this->seedBronze();

        $this->artisan('calc-v2:cutover-migrate', ['--commit' => true])->assertExitCode(0);

        $recon = app(LedgerReconciliationService::class)->check();
        $this->assertTrue($recon['ok'], 'сверка после cutover должна сходиться');
        $this->assertSame(0, $recon['trial_balance']['delta']);
        $this->assertSame([], $recon['lot_drift']);
    }
}
