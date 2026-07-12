<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusPayout;
use Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusQuarterlyPayoutService;
use Tests\TestCase;

/**
 * T09 [ДЕНЬГИ, обяз.]: квартальная выплата глобального пула — Σ final_cents трёх
 * месяцев → одна авто-проводка на ОС (кредит-лот 1 год), берёт final (не capped),
 * идемпотентность по ключу, отказ на draft/недостающем месяце, нулевые не постятся,
 * флаг OFF = no-op.
 */
class GlobalBonusQuarterlyPayoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    private int $policyVersionId;
    private CalcPeriod $p1;
    private CalcPeriod $p2;
    private CalcPeriod $p3;
    private CalcPeriod $quarter;

    protected function setUp(): void
    {
        parent::setUp();
        $policy = $this->activateGlobalBonusPolicy();
        $this->policyVersionId = $policy->versionId();
        $this->enableGlobalBonusFlag();

        $this->p1 = $this->ensurePeriod('2026-01');
        $this->p2 = $this->ensurePeriod('2026-02');
        $this->p3 = $this->ensurePeriod('2026-03');
        $this->quarter = $this->ensurePeriod('2026-Q1');
    }

    private function service(): GlobalBonusQuarterlyPayoutService
    {
        return app(GlobalBonusQuarterlyPayoutService::class);
    }

    private function pay(): array
    {
        return $this->underActivationLock(fn () => $this->service()->payQuarter(
            $this->quarter,
            [$this->p1->id, $this->p2->id, $this->p3->id],
            '2026-Q1',
        ));
    }

    public function testSumsThreeMonthsIntoOneOsCredit(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 100], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 200], $this->policyVersionId);
        $this->seedFinalMonth($this->p3, [$m => 0], $this->policyVersionId);

        $metrics = $this->pay();

        $this->assertSame(1, $metrics['members_paid']);
        $this->assertSame(300, $metrics['paid_cents']);
        // ОС кредитован ровно 300.
        $this->assertSame(300, (int) MemberAccountV2::query()->where('member_id', $m)->value('os_available_cents'));
        // Кредит-лот 1 год, source_type=global_bonus.
        $lot = WalletLotV2::query()->where('member_id', $m)->where('source_type', 'global_bonus')->firstOrFail();
        $this->assertSame(300, (int) $lot->amount_cents);
        $this->assertSame(WalletLotV2::ACCOUNT_OS, $lot->account);
        $this->assertNotNull($lot->expires_at);
        $this->assertEqualsWithDelta(365, now()->diffInDays($lot->expires_at), 2);
        // Payout-строка + аллокации помечены paid.
        $payout = GlobalBonusPayout::query()->where('quarter_period_id', $this->quarter->id)->where('member_id', $m)->firstOrFail();
        $this->assertSame(300, (int) $payout->amount_cents);
        $this->assertSame(GlobalBonusPayout::STATUS_POSTED, $payout->status);
        $this->assertSame(0, GlobalBonusAllocation::query()->where('status', GlobalBonusAllocation::STATUS_ACCRUED)->count());
    }

    public function testUsesFinalNotCapped(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 80], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 0], $this->policyVersionId);
        $this->seedFinalMonth($this->p3, [$m => 0], $this->policyVersionId);
        // Эмулируем T11: capped=999, но final=80 → выплата берёт final.
        GlobalBonusAllocation::query()->where('member_id', $m)->update(['capped_cents' => 999]);

        $metrics = $this->pay();
        $this->assertSame(80, $metrics['paid_cents']);
        $this->assertSame(80, (int) MemberAccountV2::query()->where('member_id', $m)->value('os_available_cents'));
    }

    public function testDraftMonthRefusesPayout(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 100], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 100], $this->policyVersionId);
        // p3 — draft (переводим финальный в draft).
        $draftId = $this->seedFinalMonth($this->p3, [$m => 100], $this->policyVersionId);
        DB::table('v2_global_bonus_months')->where('id', $draftId)->update(['status' => 'draft']);

        $this->expectException(\DomainException::class);
        try {
            $this->pay();
        } finally {
            // Ничего не запостилось.
            $this->assertSame(0, LedgerEntry::query()->where('source_type', 'bonus_v2')->count());
            $this->assertSame(0, GlobalBonusPayout::query()->count());
        }
    }

    public function testMissingMonthRefusesPayout(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 100], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 100], $this->policyVersionId);
        // p3 — глобальный бонус не рассчитан вовсе.

        $this->expectException(\DomainException::class);
        $this->pay();
    }

    public function testZeroSumNotPosted(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 0], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 0], $this->policyVersionId);
        $this->seedFinalMonth($this->p3, [$m => 0], $this->policyVersionId);

        $metrics = $this->pay();
        $this->assertSame(0, $metrics['members_paid']);
        $this->assertSame(0, $metrics['paid_cents']);
        $this->assertSame(0, GlobalBonusPayout::query()->count());
        $this->assertNull(MemberAccountV2::query()->where('member_id', $m)->value('os_available_cents'));
    }

    public function testIdempotentDoubleRun(): void
    {
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 150], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 150], $this->policyVersionId);
        $this->seedFinalMonth($this->p3, [$m => 0], $this->policyVersionId);

        $this->pay();
        $this->pay(); // повтор окна — no-op

        $key = "v2:glb:q:{$this->quarter->id}:m:{$m}";
        $this->assertSame(1, LedgerEntry::query()->where('idempotency_key', $key)->count());
        $this->assertSame(300, (int) MemberAccountV2::query()->where('member_id', $m)->value('os_available_cents'));
        $this->assertSame(1, GlobalBonusPayout::query()->where('member_id', $m)->count());
    }

    public function testFlagOffIsNoop(): void
    {
        app(\Modules\Calculator\Services\FeatureFlag\FeatureFlagService::class)->set('mh_v2_global_bonus', false);
        $m = $this->makeMember();
        $this->seedFinalMonth($this->p1, [$m => 100], $this->policyVersionId);
        $this->seedFinalMonth($this->p2, [$m => 100], $this->policyVersionId);
        $this->seedFinalMonth($this->p3, [$m => 100], $this->policyVersionId);

        $metrics = $this->pay();
        $this->assertSame('flag_off', $metrics['skipped']);
        $this->assertSame(0, GlobalBonusPayout::query()->count());
        $this->assertNull(MemberAccountV2::query()->where('member_id', $m)->value('os_available_cents'));
    }
}
