<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Services\Awards\Exceptions\AwardConflictException;
use Modules\Calculator\V2\Services\Awards\QualificationAwardService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Status;
use Tests\TestCase;

/**
 * T10 [ДЕНЬГИ, обязательно]: грант квалификационных наград, VP-этапы, идемпотентность,
 * ручной payout, storno-безопасность (награды не сгорают/не отзываются, DEC-027/040/042).
 */
class AwardsGrantTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2Status;

    private CarbonImmutable $at;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateV2Policy();
        $this->at = CarbonImmutable::parse('2026-02-01 12:00:00', 'UTC');
        // Реальный member — actor аудита (admin_audit_log.actor_member_id → members FK).
        $this->adminId = $this->member('Admin')->id;
    }

    private function service(): QualificationAwardService
    {
        return app(QualificationAwardService::class);
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
            $this->assertSame(
                (int) $legs->where('direction', 'debit')->sum('amount_cents'),
                (int) $legs->where('direction', 'credit')->sum('amount_cents'),
                "tx {$txId} unbalanced",
            );
        }
    }

    private function bsCreditForAward(int $memberId, int $entitlementId): int
    {
        // Кредит-лот награды: source_type='award', source_id=entitlement.
        return (int) WalletLotV2::query()
            ->where('member_id', $memberId)
            ->where('account', 'bs')
            ->where('source_type', 'award')
            ->where('source_id', $entitlementId)
            ->sum('amount_cents');
    }

    // ------------------------------------------------------------------
    // DEC-040 — все пройденные ступени при скачке
    // ------------------------------------------------------------------

    public function testRankJumpGrantsEveryCrossedAwardWithOwnBalancedLedgerGroup(): void
    {
        $m = $this->member('Jumper');
        // Скачок CONSULTANT -> GOLD_MANAGER: T05 пишет строку на каждый пройденный ранг.
        foreach ([StatusCode::MANAGER, StatusCode::BRONZE_MANAGER, StatusCode::SILVER_MANAGER, StatusCode::GOLD_MANAGER] as $rank) {
            $this->seedRank($m->id, $rank, $this->at);
        }

        $created = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        $this->assertCount(4, $created);

        $expected = [
            'MANAGER' => 10000,
            'BRONZE_MANAGER' => 20000,
            'SILVER_MANAGER' => 30000,
            'GOLD_MANAGER' => 50000,
        ];
        foreach ($expected as $code => $cents) {
            $e = AwardEntitlement::where('member_id', $m->id)->where('award_code', $code)->firstOrFail();
            $this->assertSame($cents, $e->amount_cents, "amount {$code}");
            $this->assertSame(AwardEntitlement::STATUS_GRANTED, $e->status);
            $this->assertSame($cents, $this->bsCreditForAward($m->id, $e->id), "BS lot {$code}");
        }

        // Каждая награда — ОТДЕЛЬНАЯ сбалансированная ledger-группа; БС = сумма наград.
        $this->assertAllTransactionsBalanced();
        $this->assertSame(10000 + 20000 + 30000 + 50000, $this->account($m->id)->bs_available_cents);
    }

    // ------------------------------------------------------------------
    // Все 10 сумм из PolicyVersion (решение владельца)
    // ------------------------------------------------------------------

    public function testAllTenAwardAmountsComeFromPolicyAndAreSnapshotted(): void
    {
        $m = $this->member('AllRanks');
        $ladder = [
            StatusCode::MANAGER, StatusCode::BRONZE_MANAGER, StatusCode::SILVER_MANAGER,
            StatusCode::GOLD_MANAGER, StatusCode::PLATINUM_MANAGER, StatusCode::DIRECTOR,
            StatusCode::PEARL_DIRECTOR, StatusCode::SAPPHIRE_DIRECTOR, StatusCode::DIAMOND_DIRECTOR,
            StatusCode::VICE_PRESIDENT,
        ];
        foreach ($ladder as $rank) {
            $this->seedRank($m->id, $rank, $this->at);
        }

        $created = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        $this->assertCount(10, $created);

        // USD-центы: 100/200/300/500/1500/2500/20000/35000/53000 USD + VP этап1 50000 USD.
        $expected = [
            'MANAGER' => 10000, 'BRONZE_MANAGER' => 20000, 'SILVER_MANAGER' => 30000,
            'GOLD_MANAGER' => 50000, 'PLATINUM_MANAGER' => 150000, 'DIRECTOR' => 250000,
            'PEARL_DIRECTOR' => 2000000, 'SAPPHIRE_DIRECTOR' => 3500000, 'DIAMOND_DIRECTOR' => 5300000,
        ];
        $total = 0;
        foreach ($expected as $code => $cents) {
            $this->assertSame($cents, AwardEntitlement::where('member_id', $m->id)->where('award_code', $code)->value('amount_cents'));
            $total += $cents;
        }
        $vp1 = AwardEntitlement::where('member_id', $m->id)->where('award_code', 'VICE_PRESIDENT')->where('stage_no', 1)->firstOrFail();
        $this->assertSame(5000000, $vp1->amount_cents);
        $this->assertSame(AwardEntitlement::TRIGGER_RANK_ACHIEVED, $vp1->trigger_type);
        $total += 5000000;

        $this->assertSame($total, $this->account($m->id)->bs_available_cents);
        $this->assertAllTransactionsBalanced();
    }

    // ------------------------------------------------------------------
    // Идемпотентность
    // ------------------------------------------------------------------

    public function testRepeatedDeliveryIsIdempotent(): void
    {
        $m = $this->member('Idem');
        $this->seedRank($m->id, StatusCode::DIRECTOR, $this->at);

        $first = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        $second = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        // onRankAchieved тем же событием — тоже no-op.
        $third = $this->service()->onRankAchieved($m->id, ['DIRECTOR'], $this->at, 1);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertCount(0, $third);

        $this->assertSame(1, AwardEntitlement::where('member_id', $m->id)->where('award_code', 'DIRECTOR')->count());
        // Ровно одна проводка кредита БС (idempotency key v2award:...).
        $this->assertSame(1, LedgerEntry::where('idempotency_key', "v2award:{$m->id}:DIRECTOR:1")->count());
        $this->assertSame(250000, $this->account($m->id)->bs_available_cents);
    }

    // ------------------------------------------------------------------
    // VP этапы 2-3 (DEC-042 спека A)
    // ------------------------------------------------------------------

    public function testVicePresidentThreeStages(): void
    {
        $m = $this->member('VP');
        $this->seedRank($m->id, StatusCode::VICE_PRESIDENT, $this->at);

        // Этап 1 — при достижении ранга VP.
        $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        $this->assertSame(1, AwardEntitlement::where('member_id', $m->id)->where('award_code', 'VICE_PRESIDENT')->count());

        // Этап 2 — первая месячная квалификация глобального в ранге VP.
        $this->service()->onGlobalQualificationCompleted($m->id, '2026-03');
        // Тот же месяц повторно — no-op.
        $this->service()->onGlobalQualificationCompleted($m->id, '2026-03');
        // Этап 3 — вторая distinct квалификация (ДРУГОЙ месяц).
        $this->service()->onGlobalQualificationCompleted($m->id, '2026-04');
        // Третья квалификация — ничего (нет этапа 4).
        $this->service()->onGlobalQualificationCompleted($m->id, '2026-05');

        $stages = AwardEntitlement::where('member_id', $m->id)->where('award_code', 'VICE_PRESIDENT')
            ->orderBy('stage_no')->get();
        $this->assertSame([1, 2, 3], $stages->pluck('stage_no')->all());
        $this->assertSame('2026-03', $stages->firstWhere('stage_no', 2)->trigger_ref);
        $this->assertSame('2026-04', $stages->firstWhere('stage_no', 3)->trigger_ref);
        foreach ($stages as $s) {
            $this->assertSame(5000000, $s->amount_cents);
        }
        // Полные 150 000 USD на БС (3×50 000).
        $this->assertSame(15000000, $this->account($m->id)->bs_available_cents);
        $this->assertAllTransactionsBalanced();
    }

    public function testGlobalQualificationBeforeVpIsIgnored(): void
    {
        $m = $this->member('NotVP');
        $this->seedRank($m->id, StatusCode::DIRECTOR, $this->at);
        $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);

        // Квалификация ДО достижения VP — не считается.
        $this->service()->onGlobalQualificationCompleted($m->id, '2026-03');

        $this->assertSame(0, AwardEntitlement::where('member_id', $m->id)->where('award_code', 'VICE_PRESIDENT')->count());
    }

    // ------------------------------------------------------------------
    // Ручной payout
    // ------------------------------------------------------------------

    public function testMarkPaidMovesExactlyAmountBsToPayoutsPaidAndIsIdempotent(): void
    {
        $m = $this->member('Pay');
        $this->seedRank($m->id, StatusCode::PLATINUM_MANAGER, $this->at);
        $ids = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at);
        $id = $ids[0];
        $amount = 150000;

        $this->assertSame($amount, $this->account($m->id)->bs_available_cents);

        $paid = $this->service()->markPaid($id, $this->adminId, 'ручная выплата');
        $this->assertSame(AwardEntitlement::STATUS_PAID_OUT, $paid->status);
        $this->assertSame($this->adminId, $paid->paid_by_admin_id);

        // Ровно amount ушло БС -> company_payouts_paid; БС обнулился.
        $this->assertSame(0, $this->account($m->id)->bs_available_cents);
        $this->assertSame($amount, (int) LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_BS_AVAILABLE)
            ->where('direction', 'debit')->where('source_type', 'award_payout')->sum('amount_cents'));
        $this->assertSame($amount, (int) LedgerEntry::query()
            ->where('account_type', 'company_payouts_paid')
            ->where('source_type', 'award_payout')->sum('amount_cents'));

        // Повторный markPaid — no-op (одна проводка).
        $this->service()->markPaid($id, $this->adminId, 'повтор');
        $this->assertSame(1, LedgerEntry::where('idempotency_key', "v2award:paid:{$id}")->count());
        $this->assertAllTransactionsBalanced();
    }

    public function testMarkPaidBlockedOnHoldUntilRelease(): void
    {
        $m = $this->member('Hold');
        $this->seedRank($m->id, StatusCode::MANAGER, $this->at);
        $id = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at)[0];

        $this->service()->hold($id, $this->adminId, 'проверка');
        try {
            $this->service()->markPaid($id, $this->adminId, null);
            $this->fail('markPaid должен быть заблокирован на on_hold');
        } catch (AwardConflictException $e) {
            $this->assertStringContainsString('on_hold', $e->getMessage());
        }

        // Release -> выплата снова доступна.
        $this->service()->release($id, $this->adminId, null);
        $this->service()->markPaid($id, $this->adminId, null);
        $this->assertSame(AwardEntitlement::STATUS_PAID_OUT, AwardEntitlement::find($id)->status);
    }

    public function testForfeitPaidOutRejected(): void
    {
        $m = $this->member('ForfPaid');
        $this->seedRank($m->id, StatusCode::MANAGER, $this->at);
        $id = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at)[0];
        $this->service()->markPaid($id, $this->adminId, null);

        $this->expectException(AwardConflictException::class);
        $this->service()->forfeit($id, $this->adminId, 'поздно');
    }

    // ------------------------------------------------------------------
    // Storno-безопасность (DEC-027/DEC-020) — награды не отзываются
    // ------------------------------------------------------------------

    public function testForfeitCreatesNoReversalLedgerAndKeepsAccrual(): void
    {
        $m = $this->member('Forf');
        $this->seedRank($m->id, StatusCode::DIRECTOR, $this->at);
        $id = $this->service()->reconcileMemberFromRankHistory($m->id, $this->at)[0];

        $before = LedgerEntry::count();
        $bsBefore = $this->account($m->id)->bs_available_cents;

        $this->service()->forfeit($id, $this->adminId, 'не подтверждено');

        // Начисление НЕ удаляется, reversal-проводок нет, БС не меняется.
        $this->assertSame(AwardEntitlement::STATUS_FORFEITED, AwardEntitlement::find($id)->status);
        $this->assertSame($before, LedgerEntry::count(), 'forfeit не должен писать проводок');
        $this->assertSame($bsBefore, $this->account($m->id)->bs_available_cents);
    }

    public function testNoReversalCodePathForAwardSource(): void
    {
        // Статический контракт: сервис не имеет reversal/clawback/refund-метода
        // (DEC-027 «ранг навсегда»), и ни одна проводка award-источника не отрицательна.
        $methods = get_class_methods(QualificationAwardService::class);
        foreach ($methods as $method) {
            $this->assertDoesNotMatchRegularExpression('/revers|clawback|refund|negat/i', $method, "запретный метод {$method}");
        }
        $this->assertSame(0, LedgerEntry::query()->where('amount_cents', '<=', 0)->count());
    }
}
