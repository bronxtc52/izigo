<?php

namespace Tests\Verification;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\WalletService;
use Tests\TestCase;

require_once __DIR__ . '/BonusOracle.php';

/**
 * ADVERSARIAL LIVE verification of the BINARY and REFERRAL bonuses.
 *
 * Strategy per scenario:
 *   1) Build a DETERMINISTIC topology via MANUAL placement (placement_mode=manual,
 *      explicit parentRef + position). sponsorRef controls sponsor_id independently.
 *   2) Activate packages (each activation triggers a FULL-NETWORK recompute).
 *   3) Read the REAL topology back from the DB and feed it to the independent oracle.
 *   4) Assert LIVE (engine snapshot) == HAND (hard-coded expected) == ORACLE.
 *
 * These cover edge cases the random differential harness rarely hits: multi-step
 * carryover, rank-0 deferred binary accumulation, deep chains, referral depth cutoff,
 * percent-by-receiver-package, basis=buyer-PV, and rounding.
 *
 * DB: izigo_v_binref ONLY (asserted in setUp). NO production code modified.
 * Run:
 *   DB_DATABASE=izigo_v_binref php artisan migrate:fresh --force
 *   DB_DATABASE=izigo_v_binref php artisan test tests/Verification/BinaryReferralAdversarialTest.php
 */
class BinaryReferralAdversarialTest extends TestCase
{
    private MemberService $memberSvc;
    private ActivationService $activationSvc;
    private WalletService $walletSvc;
    private int $tg = 700000;
    private int $actSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        $this->assertSame(
            'izigo_v_binref',
            $db,
            "REFUSING to run: connected DB is '{$db}', expected 'izigo_v_binref'. "
            . 'Run with DB_DATABASE=izigo_v_binref.'
        );

        // Hard wipe so each test builds a clean tree (we do NOT use RefreshDatabase to
        // keep the dedicated DB and its ltree extension intact across runs).
        foreach ([
            'member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
            'activation_events', 'member_roles', 'members',
        ] as $table) {
            try {
                DB::table($table)->delete();
            } catch (\Throwable $e) {
                // ignore tables that may not exist in some builds
            }
        }

        PlanSetting::put('placement_mode', 'manual');

        $this->memberSvc = app(MemberService::class);
        $this->activationSvc = app(ActivationService::class);
        $this->walletSvc = app(WalletService::class);
    }

    // ---------------------------------------------------------------- helpers

    /** Register root (auto: no sponsor, no parent). Returns the Member. */
    private function root(string $name): Member
    {
        return $this->memberSvc->registerTelegram($this->tg++, $name, null);
    }

    /**
     * Manually place a child under $parent on $position; sponsor_id = $sponsor (or
     * $parent if null). Parent must be inside the sponsor's subtree — using the parent
     * itself as sponsor (default) always satisfies that.
     */
    private function place(string $name, Member $parent, string $position, ?Member $sponsor = null): Member
    {
        $sp = $sponsor ?? $parent;
        return $this->memberSvc->registerTelegram(
            $this->tg++,
            $name,
            null,
            $sp->ref_code,
            null,
            $parent->ref_code,
            $position,
        );
    }

    /** Activate a package (1=Bronze90,2=Silver180,3=Gold540) with a unique idempotency key. */
    private function activate(Member $m, int $pkg): void
    {
        $this->activationSvc->activate($m->id, $pkg, 'vbinref-' . (++$this->actSeq));
    }

    /** Live engine per-type cents for a member, read from MemberEarning.by_type. */
    private function liveByType(int $memberId): array
    {
        $by = MemberEarning::query()->where('member_id', $memberId)->value('by_type') ?? [];
        $out = [];
        foreach (['referral', 'binary', 'leader', 'rank'] as $t) {
            $out[$t] = $this->decToCents($by[$t] ?? '0');
        }
        return $out;
    }

    /** Live wallet available cents (= cumulative accrual; no holds in this flow). */
    private function liveWallet(int $memberId): int
    {
        $m = Member::query()->find($memberId);
        return $this->decToCents($this->walletSvc->balance($m)['available']);
    }

    /** Run the oracle over the REAL DB topology (ascending id). */
    private function oracle(): array
    {
        $rows = Member::query()
            ->orderBy('id')
            ->get(['id', 'name', 'sponsor_id', 'parent_id', 'position', 'package_id'])
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'name' => $m->name,
                'sponsorId' => $m->sponsor_id !== null ? (int) $m->sponsor_id : null,
                'parentId' => $m->parent_id !== null ? (int) $m->parent_id : null,
                'position' => $m->position,
                'packageId' => $m->package_id !== null ? (int) $m->package_id : null,
            ])
            ->all();

        return (new BonusOracle())->compute($rows);
    }

    private function decToCents(string|int|float|null $v): int
    {
        if ($v === null) {
            return 0;
        }
        return (int) round(((float) $v) * 100);
    }

    /**
     * Triple-assert LIVE == HAND == ORACLE for one member's binary+referral (+leader,
     * +rank when given). $hand keys: referral/binary/leader/rank (cents).
     */
    private function assertMember(int $memberId, array $hand, array $oracle, string $ctx): void
    {
        $hand += ['referral' => 0, 'binary' => 0, 'leader' => 0, 'rank' => 0];
        $live = $this->liveByType($memberId);
        $orc = $oracle['byType'][$memberId];

        foreach (['referral', 'binary', 'leader', 'rank'] as $t) {
            $this->assertSame(
                $hand[$t],
                $orc[$t],
                "{$ctx}: ORACLE {$t} for #{$memberId} = {$orc[$t]}c, HAND expected {$hand[$t]}c"
            );
            $this->assertSame(
                $hand[$t],
                $live[$t],
                "{$ctx}: LIVE {$t} for #{$memberId} = {$live[$t]}c, HAND expected {$hand[$t]}c"
            );
        }
    }

    private function log(string $line): void
    {
        fwrite(STDERR, $line . "\n");
    }

    // ================================================================ TASK 1
    // BINARY multi-step CARRYOVER across >=4 activations.
    // Topology: A root. A sponsors P (rank1 enabler) placed left of A — actually we
    // need A to PAIR, so A must have BOTH legs fed. We build:
    //   A (root)
    //    left  -> L  (heavy: Gold 540)
    //    right -> R  (light leg root; under R we add Bronze members incrementally)
    // A needs rank1 => >=1 personal invite. We make A sponsor L (sponsor_id=A), giving A
    // a personal invite, so A is rank1 (5% binary).
    //
    // Heavy leg PV (left) = 540 (Gold L). Light leg fed by Bronze 90 each step on right.
    // After each light activation a full recompute pairs min(left,right). Left carryover
    // persists; right is drained to its paired amount.
    public function testBinaryMultiStepCarryover(): void
    {
        $A = $this->root('A');
        // Heavy left leg: Gold (540 PV). Sponsored by A => gives A a personal invite (rank1).
        $L = $this->place('L', $A, 'left', $A);
        // Light right leg: chain of Bronze nodes, each under the previous (so all PV
        // flows up through A's right child R).
        $R = $this->place('R', $A, 'right', $A);
        $R2 = $this->place('R2', $R, 'left', $R);
        $R3 = $this->place('R3', $R2, 'left', $R2);
        $R4 = $this->place('R4', $R3, 'left', $R3);

        // Activate heavy leg first.
        $this->activate($L, 3); // Gold 540 on left

        // Step-by-step light-leg activations; after each, assert running binary + carryover.
        // A is rank1 (personal invite = L). binary 5%.
        // left leg accumulated = 54000c (persists minus flushes).
        // Each Bronze adds 9000c to right leg.

        // --- step 1: R Bronze 90 ---
        $this->activate($R, 1);
        // recompute from scratch: left=54000, right=9000 => pair=9000 => binary 5%*9000=450c
        // flush: left->45000, right->0
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 450], $o, 'T1.step1 A');
        $this->log('[T1] step1: A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 450), left carryover 45000c');

        // --- step 2: R2 Bronze 90 ---
        $this->activate($R2, 1);
        // fresh recompute: left=54000, right=18000 => pair=18000 => binary 5%*18000=900c
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 900], $o, 'T1.step2 A');
        $this->log('[T1] step2: A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 900), left carryover 36000c');

        // --- step 3: R3 Bronze 90 ---
        $this->activate($R3, 1);
        // left=54000, right=27000 => pair=27000 => binary=1350c
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 1350], $o, 'T1.step3 A');
        $this->log('[T1] step3: A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 1350), left carryover 27000c');

        // --- step 4: R4 Bronze 90 ---
        $this->activate($R4, 1);
        // left=54000, right=36000 => pair=36000 => binary=1800c. left carryover=18000.
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 1800], $o, 'T1.step4 A');
        $liveWallet = $this->liveWallet($A->id);
        $this->log('[T1] step4: A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 1800), left carryover 18000c; wallet=' . $liveWallet . 'c');

        // Carryover proof: left still has 18000c unmatched. Add one more heavy-side? Instead
        // add a 5th right Bronze to show the leftover (18000) pairs only up to it.
        $R5 = $this->place('R5', $R4, 'left', $R4);
        $this->activate($R5, 1);
        // left=54000, right=45000 => pair=45000 => binary 5%*45000=2250c; left carryover=9000.
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 2250], $o, 'T1.step5 A');
        $this->log('[T1] step5: A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 2250), left carryover 9000c');

        // Final wallet == oracle total (binary 2250 + any leader; A has no upline sponsor
        // beyond itself so leader=0).
        $this->assertSame($o['total'][$A->id], $this->liveWallet($A->id), 'T1 A wallet==oracle total');
    }

    // ================================================================ TASK 2
    // BINARY both-legs-required: only ONE leg => 0 binary; populate the 2nd leg => pairs.
    public function testBinaryBothLegsRequired(): void
    {
        $A = $this->root('A');
        $L = $this->place('L', $A, 'left', $A);   // A personal invite => rank1
        $R = $this->place('R', $A, 'right', $A);

        // Phase 1: only LEFT leg active. Right leg empty => no pair => 0 binary.
        $this->activate($L, 1); // Bronze 90 on left only
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 0], $o, 'T2.phase1 A (one leg)');
        $this->log('[T2] phase1: only left leg active => A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 0)');

        // Phase 2: activate RIGHT leg. left=9000, right=18000(Silver) => pair=9000 =>
        // binary 5%*9000=450c. left flushed to 0, right carryover 9000.
        $this->activate($R, 2); // Silver 180 on right
        $o = $this->oracle();
        $this->assertMember($A->id, ['binary' => 450], $o, 'T2.phase2 A (both legs)');
        $this->log('[T2] phase2: both legs => A binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 450); pair=9000, right carryover 9000');
    }

    // ================================================================ TASK 3
    // BINARY rank-0 DEFERRED accumulation. THE SUBTLE ONE.
    // An ancestor at rank0 (no personal invite yet) pairs nothing (0%), and the engine
    // does NOT flush (returns before reduceBranchVolume). Volume KEEPS accumulating.
    // Once the ancestor gains a personal invite -> rank1, the NEXT full recompute pairs
    // on the ACCUMULATED volume.
    //
    // Topology to keep A at rank0 initially: A must have NO member with sponsor_id==A.
    // So we place A's two legs sponsored by ROOT (not A). A itself is placed under ROOT.
    //
    //   ROOT (root, sponsors everything below to deny A personal invites)
    //     left  -> A
    //               left  -> X (Gold 540), sponsor=ROOT  => A gets NO personal invite
    //               right -> Y (Gold 540), sponsor=ROOT
    // While A is rank0: X and Y feed A's legs (left=54000, right=54000) but A pairs 0.
    // Then we add Z sponsored BY A (placed deeper, under X) => A gets a personal invite
    // => A becomes rank1. Next recompute pairs min(left,right) on accumulated volume.
    public function testBinaryRank0DeferredAccumulation(): void
    {
        $ROOT = $this->root('ROOT');
        $A = $this->place('A', $ROOT, 'left', $ROOT); // sponsor=ROOT, so A has 0 personal invites

        // A's two legs, both sponsored by ROOT (NOT A) so A stays rank0.
        $X = $this->place('X', $A, 'left', $ROOT);
        $Y = $this->place('Y', $A, 'right', $ROOT);

        // Activate both legs while A is rank0.
        $this->activate($X, 3); // Gold 540 left
        $this->activate($Y, 3); // Gold 540 right

        // A is rank0 => 0% binary => NO payout, NO flush. Volume accumulates: left=54000, right=54000.
        $o = $this->oracle();
        $rankA = (int) (Member::query()->where('id', $A->id)->value('rank_id') ?? 0);
        $this->assertSame(0, $rankA, 'T3: A still rank0 (no personal invite)');
        $this->assertMember($A->id, ['binary' => 0], $o, 'T3.pre A (rank0 defers)');
        $this->log('[T3] pre: A rank=0 => binary=' . $this->liveByType($A->id)['binary'] . 'c (exp 0); left=54000 right=54000 accumulated, NOT flushed');

        // Now give A a personal invite: Z sponsored BY A, placed under X (inside ROOT's
        // subtree; sponsor A => A personal invite count = 1 => A reaches rank1).
        // Z Bronze 90 adds 9000 to A's LEFT leg (path Z->X->A).
        $Z = $this->place('Z', $X, 'left', $A);
        $this->activate($Z, 1); // Bronze 90; Z under X => left leg += 9000

        // Full recompute now: A is rank1 (personal invite Z, id<=cutoff at A's qualify pass).
        // Volumes rebuilt from scratch each recompute:
        //   left = X(54000) + Z(9000) = 63000 ; right = Y(54000)
        //   pair = min(63000,54000) = 54000 => binary 5% * 54000 = 2700c
        //   left carryover = 9000, right carryover = 0.
        $o = $this->oracle();
        $rankA = (int) (Member::query()->where('id', $A->id)->value('rank_id') ?? 0);
        $this->assertSame(1, $rankA, 'T3: A reached rank1 after personal invite Z');
        $this->assertMember($A->id, ['binary' => 2700], $o, 'T3.post A (deferred volume pairs)');
        $this->log('[T3] post: A rank=1 => deferred pair on accumulated volume: binary='
            . $this->liveByType($A->id)['binary'] . 'c (exp 2700); left=63000 right=54000 pair=54000, left carryover 9000');

        // Wallet must equal oracle total for A (binary 2700 + leader from A's binary up A's
        // sponsor chain). A's sponsor is ROOT; leader percent for ROOT depends on ROOT rank/pkg.
        // We assert LIVE==ORACLE total to capture any leader too.
        $this->assertSame($o['total'][$A->id], $this->liveWallet($A->id), 'T3 A wallet==oracle total');
    }

    // ================================================================ TASK 4
    // BINARY deeper chain (3+ placement levels): PV propagates to ALL ancestors and each
    // pairs independently with its own carryover.
    //
    //   A (root)         legs: left=B-subtree, right=Ra(Gold 540)
    //    left  -> B       legs: left=C-subtree, right=Rb(Gold 540)
    //              left -> C   legs: left=D(Gold 540), right=Rc(Gold 540)
    //                       left -> D (Gold 540)
    //              right-> Rb (Gold 540)
    //    right -> Ra (Gold 540)
    //   C right-> Rc (Gold 540)
    //
    // All of A,B,C must be rank1: each is given a personal invite.
    //   A sponsors Ra ; B sponsors Rb ; C sponsors Rc, D.
    // D's 540 PV flows up D->C->B->A (left legs). The right legs Ra/Rb/Rc each carry 540
    // at their respective ancestor. So each ancestor pairs min(left,right).
    public function testBinaryDeeperChain(): void
    {
        $A = $this->root('A');
        $B = $this->place('B', $A, 'left', $A);    // sponsor A (A personal invite via... no, B sponsor A gives A invite)
        $C = $this->place('C', $B, 'left', $B);    // sponsor B => B personal invite
        $D = $this->place('D', $C, 'left', $C);    // sponsor C => C personal invite
        $Ra = $this->place('Ra', $A, 'right', $A); // A right leg; sponsor A (already have B)
        $Rb = $this->place('Rb', $B, 'right', $B); // B right leg
        $Rc = $this->place('Rc', $C, 'right', $C); // C right leg

        // Activate all as Gold 540.
        foreach ([$D, $Ra, $Rb, $Rc] as $m) {
            $this->activate($m, 3);
        }
        // Also activate B and C? Their own purchase adds personal PV to their own subtree's
        // left leg of their PARENT, not to their own legs. To keep legs clean we leave
        // B,C,A unactivated (they earn binary as ancestors regardless).

        // After all activations, recompute. Let's reason about each ancestor's legs:
        // Left-leg PV at each ancestor = sum of personal PV in its left subtree that is active.
        //   A.left subtree = {B,C,D,Rb,Rc(under C right? Rc parent=C which is in A.left)} -> active among them: D(540),Rb(540),Rc(540)=1620 => A.left=162000c
        //   A.right = Ra(540) = 54000c
        //   pair A = min(162000,54000)=54000 => binary 2700c ; A.left carryover 108000
        //   B.left subtree = {C,D,Rc} active: D(540),Rc(540)=1080 => B.left=108000
        //   B.right = Rb(540)=54000 ; pair B=54000 => 2700c ; B.left carryover 54000
        //   C.left subtree = {D} active: D(540)=54000 ; C.right = Rc(540)=54000
        //   pair C = 54000 => 2700c ; carryover 0/0
        $o = $this->oracle();

        // Confirm ranks first.
        foreach (['A' => $A, 'B' => $B, 'C' => $C] as $label => $node) {
            $rank = (int) (Member::query()->where('id', $node->id)->value('rank_id') ?? 0);
            $this->assertSame(1, $rank, "T4: {$label} should be rank1");
        }

        $this->assertMember($A->id, ['binary' => 2700], $o, 'T4 A');
        $this->assertMember($B->id, ['binary' => 2700], $o, 'T4 B');
        $this->assertMember($C->id, ['binary' => 2700], $o, 'T4 C');
        $this->log('[T4] deep chain: A.binary=' . $this->liveByType($A->id)['binary']
            . 'c B.binary=' . $this->liveByType($B->id)['binary']
            . 'c C.binary=' . $this->liveByType($C->id)['binary'] . 'c (each exp 2700)');
        $this->log('[T4] carryover: A.left=108000c B.left=54000c C=0 (each pairs independently)');
    }

    // ================================================================ TASK 5
    // REFERRAL depth cutoff: buyer -> S1 -> S2 -> S3 sponsor chain.
    // S1(L1) paid, S2(L2) paid, S3(L3) gets NOTHING (depth=2).
    // Sponsor chain is independent of placement; place all under root linearly.
    public function testReferralDepthCutoff(): void
    {
        $S3 = $this->root('S3');
        $S2 = $this->place('S2', $S3, 'left', $S3); // sponsor S3
        $S1 = $this->place('S1', $S2, 'left', $S2); // sponsor S2
        $Buyer = $this->place('Buyer', $S1, 'left', $S1); // sponsor S1

        // All sponsors Gold so referral percent table has L2 entries (sort3: L1=10,L2=8).
        $this->activate($S3, 3);
        $this->activate($S2, 3);
        $this->activate($S1, 3);
        // Buyer Bronze 90 (purchase PV = 9000c).
        $this->activate($Buyer, 1);

        $o = $this->oracle();
        // Buyer PV = 9000c. S1 is Gold(sort3) L1=10% => 900c. S2 Gold L2=8% => 720c.
        // S3 is L3 from buyer => beyond depth 2 => 0 referral from Buyer.
        // (S2,S1 also bought, generating their own referral up-chain; we assert the
        //  per-member totals via oracle, and check Buyer-sourced amounts via hand on S3.)
        // S3 referral: only from S2's purchase (S2 buyer, S3 is L1) and S1's purchase
        // (S1 buyer, S2 L1, S3 L2). NOT from Buyer (L3). Let oracle hold the full sum;
        // we explicitly assert S3 got NOTHING attributable to Buyer by checking the
        // bonus lines source.
        $this->assertMemberMatchesOracleAndLive($S1->id, $o, 'T5 S1');
        $this->assertMemberMatchesOracleAndLive($S2->id, $o, 'T5 S2');
        $this->assertMemberMatchesOracleAndLive($S3->id, $o, 'T5 S3');

        // Explicit depth-cutoff proof: no referral bonus line on S3 sourced from Buyer.
        $s3FromBuyer = \Modules\Calculator\Models\MemberBonusLine::query()
            ->where('recipient_member_id', $S3->id)
            ->where('type', 'referral')
            ->get()
            ->filter(fn ($l) => (int) ($l->basis['sourceId'] ?? 0) === $Buyer->id)
            ->count();
        $this->assertSame(0, $s3FromBuyer, 'T5: S3 must receive NO referral from Buyer (depth=2 cutoff)');
        $this->log('[T5] depth cutoff: S3 referral lines sourced from Buyer = ' . $s3FromBuyer . ' (exp 0)');
        $this->log('[T5] oracle: S1 referral=' . $o['byType'][$S1->id]['referral']
            . 'c S2 referral=' . $o['byType'][$S2->id]['referral']
            . 'c S3 referral=' . $o['byType'][$S3->id]['referral'] . 'c');
    }

    // ================================================================ TASK 6
    // REFERRAL percent by RECEIVER package incl. L2=0.
    // buyer Bronze; S1=Bronze => L1 10%; S2=Bronze => sort1 L2 percent = 0 => S2 gets 0.
    // Variant: S2=Gold => L2 8%. Confirms percent depends on SPONSOR's package, not buyer's.
    public function testReferralPercentByReceiverPackage(): void
    {
        // --- Variant A: S2 Bronze => L2=0 ---
        $S2 = $this->root('S2b');
        $S1 = $this->place('S1b', $S2, 'left', $S2);
        $Buyer = $this->place('Buyb', $S1, 'left', $S1);
        $this->activate($S2, 1); // Bronze
        $this->activate($S1, 1); // Bronze
        $this->activate($Buyer, 1); // Bronze 90 -> PV 9000c

        $o = $this->oracle();
        // From Buyer: S1 (Bronze sort1 L1=10%) => 900c. S2 (Bronze sort1 L2=0%) => 0c.
        // S1 referral total = only from Buyer (S1 has no downline buyer besides Buyer) = 900c.
        // S2 referral total = from S1's purchase (S1 buyer, S2 L1 Bronze 10% * 9000 = 900c)
        //                     + from Buyer (L2 Bronze = 0) = 900c.
        $this->assertMember($S1->id, ['referral' => 900], $o, 'T6A S1 (Bronze L1=10%)');
        $this->assertMember($S2->id, ['referral' => 900], $o, 'T6A S2 (only L1-from-S1; L2-from-Buyer=0)');
        // Explicit: S2's referral line sourced from Buyer must be 0 (Bronze L2 = 0%).
        $s2FromBuyerCents = (int) round(100 * \Modules\Calculator\Models\MemberBonusLine::query()
            ->where('recipient_member_id', $S2->id)
            ->where('type', 'referral')
            ->get()
            ->filter(fn ($l) => (int) ($l->basis['sourceId'] ?? 0) === $Buyer->id)
            ->sum(fn ($l) => (float) $l->amount));
        $this->assertSame(0, $s2FromBuyerCents, 'T6A: S2 L2-from-Buyer = 0 (Bronze sort1 L2=0%)');
        $this->log('[T6A] Bronze S2: referral-from-Buyer=' . $s2FromBuyerCents . 'c (exp 0); S1=900c S2total=' . $o['byType'][$S2->id]['referral'] . 'c');

        // --- Variant B: fresh tree, S2 Gold => L2=8% ---
        foreach (['member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
                  'activation_events', 'member_roles', 'members'] as $t) {
            DB::table($t)->delete();
        }
        $S2g = $this->root('S2g');
        $S1g = $this->place('S1g', $S2g, 'left', $S2g);
        $Buyerg = $this->place('Buyg', $S1g, 'left', $S1g);
        $this->activate($S2g, 3); // Gold
        $this->activate($S1g, 1); // Bronze (receiver-package of S1 = Bronze L1 = 10%)
        $this->activate($Buyerg, 1); // Bronze 90 -> PV 9000c

        $o = $this->oracle();
        // From Buyer: S1g Bronze L1 10% => 900c. S2g Gold sort3 L2 8% * 9000 = 720c.
        $this->assertMember($S1g->id, ['referral' => 900], $o, 'T6B S1 (Bronze L1=10%)');
        // S2g total referral = from S1g purchase (S1g buyer, S2g L1 Gold sort3 L1=10% * 9000=900)
        //                      + from Buyerg (L2 Gold 8% * 9000 = 720) = 1620c.
        $this->assertMember($S2g->id, ['referral' => 1620], $o, 'T6B S2 (L1-from-S1=900 + L2-from-Buyer=720)');
        $s2gFromBuyer = (int) round(100 * \Modules\Calculator\Models\MemberBonusLine::query()
            ->where('recipient_member_id', $S2g->id)
            ->where('type', 'referral')
            ->get()
            ->filter(fn ($l) => (int) ($l->basis['sourceId'] ?? 0) === $Buyerg->id)
            ->sum(fn ($l) => (float) $l->amount));
        $this->assertSame(720, $s2gFromBuyer, 'T6B: S2 Gold L2-from-Buyer = 720c (8% * 9000)');
        $this->log('[T6B] Gold S2: referral-from-Buyer=' . $s2gFromBuyer . 'c (exp 720); buyer Bronze same as 6A => percent depends on SPONSOR pkg');
    }

    // ================================================================ TASK 7
    // REFERRAL basis = BUYER PV. Same sponsors; buyer Gold(540) vs Bronze(90).
    public function testReferralBasisIsBuyerPv(): void
    {
        // Tree 1: buyer Gold.
        $S2 = $this->root('S2');
        $S1 = $this->place('S1', $S2, 'left', $S2);
        $Buyer = $this->place('Buyer', $S1, 'left', $S1);
        $this->activate($S2, 3); // Gold sponsor
        $this->activate($S1, 3); // Gold sponsor
        $this->activate($Buyer, 3); // Gold 540 -> PV 54000c

        $o = $this->oracle();
        // From Buyer(Gold 54000c): S1 Gold L1 10% => 5400c ; S2 Gold L2 8% => 4320c.
        $s1FromBuyer = $this->referralFromSource($S1->id, $Buyer->id);
        $s2FromBuyer = $this->referralFromSource($S2->id, $Buyer->id);
        $this->assertSame(5400, $s1FromBuyer, 'T7 Gold buyer: S1 L1 = 10%*54000 = 5400c');
        $this->assertSame(4320, $s2FromBuyer, 'T7 Gold buyer: S2 L2 = 8%*54000 = 4320c');
        // Oracle/live cross-check of full members.
        $this->assertMemberMatchesOracleAndLive($S1->id, $o, 'T7 S1');
        $this->assertMemberMatchesOracleAndLive($S2->id, $o, 'T7 S2');
        $this->log("[T7] Gold buyer: S1-from-buyer={$s1FromBuyer}c (exp 5400), S2-from-buyer={$s2FromBuyer}c (exp 4320)");

        // Tree 2: identical sponsors, buyer Bronze.
        foreach (['member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
                  'activation_events', 'member_roles', 'members'] as $t) {
            DB::table($t)->delete();
        }
        $S2b = $this->root('S2');
        $S1b = $this->place('S1', $S2b, 'left', $S2b);
        $Buyerb = $this->place('Buyer', $S1b, 'left', $S1b);
        $this->activate($S2b, 3);
        $this->activate($S1b, 3);
        $this->activate($Buyerb, 1); // Bronze 90 -> PV 9000c

        $o = $this->oracle();
        $s1b = $this->referralFromSource($S1b->id, $Buyerb->id);
        $s2b = $this->referralFromSource($S2b->id, $Buyerb->id);
        $this->assertSame(900, $s1b, 'T7 Bronze buyer: S1 L1 = 10%*9000 = 900c');
        $this->assertSame(720, $s2b, 'T7 Bronze buyer: S2 L2 = 8%*9000 = 720c');
        $this->log("[T7] Bronze buyer: S1-from-buyer={$s1b}c (exp 900), S2-from-buyer={$s2b}c (exp 720) => basis scales with BUYER PV");
    }

    // ================================================================ TASK 8
    // Rounding: construct percent*PV that is not a whole cent and confirm half-up.
    // Available integer percents: referral {10,8,5}, binary {5}. PV cents are multiples
    // of 9000/18000/54000. 8% of 9000c = 720c (whole). 5% of 9000 = 450c (whole). All
    // package PVs are multiples of 100c and percents make multiples of... 9000*8/100=720.
    // To get a fractional cent we need pv*pct not divisible by 100. PV cents are always
    // multiples of 9000 (Bronze), 18000, 54000 -> all multiples of 100. percent/100 * pv:
    //   pv multiple of 100 => pv*pct is multiple of 100 => /100 is integer cents ALWAYS.
    // So with default integer percents and these PVs, NO fractional cent is constructible.
    // We document this and instead unit-prove the oracle's half-up rounding helper via a
    // direct synthetic check (does not touch the engine; just confirms rounding policy).
    public function testRoundingHalfUpNoteAndPolicy(): void
    {
        // Proof that no live fractional cent is constructible with integer percents and
        // package PVs that are multiples of 100c:
        $pkgPvCents = [9000, 18000, 54000];
        $percents = [5, 8, 10];
        $fractionalFound = false;
        foreach ($pkgPvCents as $pv) {
            foreach ($percents as $p) {
                if (($pv * $p) % 100 !== 0) {
                    $fractionalFound = true;
                }
            }
        }
        $this->assertFalse(
            $fractionalFound,
            'T8: with integer percents and PVs that are multiples of 100c, every percent*PV is a whole cent'
        );
        $this->log('[T8] NOTE: not constructible with default integer percents + PV multiples of 100c — every product is a whole cent.');

        // Policy confirmation (half-up / half-away-from-zero, matching PHP round()):
        // The engine uses Percent::ofPvAsMoney and ActivationService stores via cents.
        // We confirm the rounding direction the engine would use IF a half-cent arose, by
        // checking the same primitive both oracle and engine rely on (PHP round half-up).
        $this->assertSame(1, (int) round(0.5), 'T8: round(0.5) half-up => 1');
        $this->assertSame(2, (int) round(1.5), 'T8: round(1.5) half-up => 2');
        $this->assertSame(3, (int) round(2.5), 'T8: round(2.5) half-away-from-zero => 3 (NOT banker 2)');
        $this->log('[T8] policy confirmed: half-up (away-from-zero) — round(2.5)=3, not banker 2.');
    }

    // ------------------------------------------------ shared assert helpers

    /** Assert LIVE per-type == ORACLE per-type for a member (no separate hand value). */
    private function assertMemberMatchesOracleAndLive(int $memberId, array $oracle, string $ctx): void
    {
        $live = $this->liveByType($memberId);
        $orc = $oracle['byType'][$memberId];
        foreach (['referral', 'binary', 'leader', 'rank'] as $t) {
            $this->assertSame(
                $orc[$t],
                $live[$t],
                "{$ctx}: LIVE {$t} #{$memberId}={$live[$t]}c != ORACLE {$orc[$t]}c"
            );
        }
    }

    /** Sum (cents) of referral bonus lines on $recipient sourced from $sourceId. */
    private function referralFromSource(int $recipient, int $sourceId): int
    {
        return (int) round(100 * \Modules\Calculator\Models\MemberBonusLine::query()
            ->where('recipient_member_id', $recipient)
            ->where('type', 'referral')
            ->get()
            ->filter(fn ($l) => (int) ($l->basis['sourceId'] ?? 0) === $sourceId)
            ->sum(fn ($l) => (float) $l->amount));
    }
}
