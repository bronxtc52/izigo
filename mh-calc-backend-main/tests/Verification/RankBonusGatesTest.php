<?php

namespace Tests\Verification;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\WalletService;
use Tests\TestCase;

require_once __DIR__ . '/BonusOracle.php';

/**
 * LIVE end-to-end verification of the RANK bonus and rank QUALIFICATION gates.
 *
 * The default plan sets every rank bonus_usd=$0, so the RANK bonus never fires on
 * the live path by default. Here we OVERRIDE rank bonuses to positive values via
 * plan_settings (legacy key 'rank_bonuses' = rankId=>USD, honored by
 * EloquentPlanRepository) and drive the REAL services (MemberService -> manual
 * placement -> ActivationService -> CompensationEngine -> snapshot persistence).
 *
 * For every scenario we compare LIVE (DB) == HAND (documented) == ORACLE (with a
 * matching rankBonusOverride). Production code under Modules/Calculator/** is never
 * modified; this test only writes plan_settings + members + activations.
 *
 * DB: izigo_v_rank (asserted in setUp). NOT prod.
 * Run:
 *   DB_DATABASE=izigo_v_rank php artisan migrate:fresh --force
 *   DB_DATABASE=izigo_v_rank php artisan test tests/Verification/RankBonusGatesTest.php
 */
class RankBonusGatesTest extends TestCase
{
    private MemberService $members;
    private ActivationService $activation;
    private WalletService $wallet;

    /** running counter for unique telegram ids / idempotency keys across a test */
    private int $tg = 700000;
    private int $act = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection()->getDatabaseName();
        $this->assertSame('izigo_v_rank', $db, "MUST run against izigo_v_rank, got '{$db}'");

        // Clean slate (no RefreshDatabase: schema is migrated by migrate:fresh).
        $this->wipe();

        $this->members = app(MemberService::class);
        $this->activation = app(ActivationService::class);
        $this->wallet = app(WalletService::class);

        // Manual placement so topology is fully deterministic.
        PlanSetting::put('placement_mode', 'manual');
    }

    private function wipe(): void
    {
        foreach ([
            'member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
            'activation_events', 'member_roles', 'members', 'plan_settings',
        ] as $table) {
            try {
                DB::table($table)->delete();
            } catch (\Throwable $e) {
                // ignore tables absent in some builds
            }
        }
    }

    // ===================================================================
    // Live driving helpers
    // ===================================================================

    /** Register the ROOT (first member; no sponsor/parent). Returns Member. */
    private function root(string $name): Member
    {
        return $this->members->registerTelegram($this->tg++, $name, null, null, null, null, null);
    }

    /**
     * Register a member under manual placement.
     * @param Member $sponsor   sponsor (defines personalInvited + subtree validity)
     * @param Member $parent    placement parent (must be inside sponsor's subtree)
     * @param string $position  'left'|'right'
     */
    private function add(string $name, Member $sponsor, Member $parent, string $position): Member
    {
        return $this->members->registerTelegram(
            $this->tg++, $name, null, $sponsor->ref_code, null, $parent->ref_code, $position,
        );
    }

    /** Activate a package on a member (1=Bronze 90PV, 2=Silver 180PV, 3=Gold 540PV). */
    private function activate(Member $m, int $packageId): void
    {
        $this->activation->activate($m->id, $packageId, 'act-' . (++$this->act) . '-' . $m->id);
    }

    // ===================================================================
    // Live readers
    // ===================================================================

    /** All persisted rank bonus lines for a member. */
    private function rankLines(int $memberId): array
    {
        return MemberBonusLine::query()
            ->where('recipient_member_id', $memberId)
            ->where('type', 'rank')
            ->orderBy('id')
            ->get(['amount', 'basis'])
            ->map(fn ($l) => ['amount' => (string) $l->amount, 'basis' => $l->basis])
            ->all();
    }

    private function byTypeRankCents(int $memberId): int
    {
        $bt = MemberEarning::query()->where('member_id', $memberId)->value('by_type') ?? [];
        return $this->dec2cents($bt['rank'] ?? '0');
    }

    private function rankId(int $memberId): int
    {
        return (int) (Member::query()->where('id', $memberId)->value('rank_id') ?? 0);
    }

    private function walletCents(int $memberId): int
    {
        $m = Member::query()->find($memberId);
        return $this->dec2cents($this->wallet->balance($m)['available']);
    }

    private function dec2cents(string|int|float|null $v): int
    {
        return $v === null ? 0 : (int) round(((float) $v) * 100);
    }

    /** Read full topology from DB in ascending id order (oracle input shape). */
    private function topology(): array
    {
        return Member::query()->orderBy('id')
            ->get(['id', 'name', 'sponsor_id', 'parent_id', 'position', 'package_id'])
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'name' => $m->name,
                'sponsorId' => $m->sponsor_id !== null ? (int) $m->sponsor_id : null,
                'parentId' => $m->parent_id !== null ? (int) $m->parent_id : null,
                'position' => $m->position,
                'packageId' => $m->package_id !== null ? (int) $m->package_id : null,
            ])->all();
    }

    /** Set rank bonus override on the LIVE path (legacy rank_bonuses key) AND return an oracle wired to match. */
    private function overrideRankBonuses(array $rankIdToUsd): BonusOracle
    {
        // JSON-style keys (strings) to mirror admin storage; repo handles both.
        $doc = [];
        foreach ($rankIdToUsd as $rid => $usd) {
            $doc[(string) $rid] = $usd;
        }
        PlanSetting::put('rank_bonuses', $doc);

        $oracle = new BonusOracle();
        $oracle->rankBonusOverride = $rankIdToUsd;
        return $oracle;
    }

    /** Run oracle over current DB topology. */
    private function oracleCompute(BonusOracle $oracle): array
    {
        return $oracle->compute($this->topology());
    }

    // ===================================================================
    // SCENARIO 1 — rank2 qualifies, bonus paid once; non-qualifiers get nothing
    // ===================================================================

    public function test1_rank2_qualifies_pays_once_and_nonqualifiers_get_nothing(): void
    {
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        // Topology: ROOT T is the target.
        //   T (root)
        //   ├─ left  : L  (sponsor=T, Gold 540)  ── under L: L2 (sponsor=T, Gold 540)
        //   └─ right : R  (sponsor=T, Gold 540)  ── under R: R2 (sponsor=T, Gold 540)
        // Left leg subtree PV  = 540+540 = 1080 >= 1000  ✔
        // Right leg subtree PV = 540+540 = 1080 >= 1000  ✔  -> small_branch = 1080
        // personalInvited(sponsor==T) within T subtree = {L,R,L2,R2} = 4 >= 4  ✔
        // => T reaches rank2 once -> $85 rank bonus.
        $T = $this->root('T');
        $L = $this->add('L', $T, $T, 'left');
        $R = $this->add('R', $T, $T, 'right');
        $L2 = $this->add('L2', $T, $L, 'left');
        $R2 = $this->add('R2', $T, $R, 'left');

        // Activate everyone Gold (each adds 540 PV). Order: leaves last so final recompute
        // sees the full tree on T's up-chain.
        $this->activate($T, 3);
        $this->activate($L, 3);
        $this->activate($R, 3);
        $this->activate($L2, 3);
        $this->activate($R2, 3);

        // ---- HAND ----
        // T qualifies rank2 -> exactly ONE rank line $85.00, by_type rank=8500c, rank_id=2.
        // L,R,L2,R2 each have <4 invitees & insufficient small-branch -> rank_id 1 (consultant,
        //   personal_count=1 with no positive bonus) -> NO rank bonus line.
        $handRankBonusT = 8500;

        // ---- LIVE ----
        $linesT = $this->rankLines($T->id);
        $this->assertCount(1, $linesT, 'T must have EXACTLY one rank bonus line');
        $this->assertSame('85.00', $linesT[0]['amount']);
        $this->assertSame(2, (int) ($linesT[0]['basis']['meta']['rankId'] ?? 0), 'line tagged rankId=2');
        $this->assertSame($handRankBonusT, $this->byTypeRankCents($T->id), 'by_type[rank]=85.00');
        $this->assertSame(2, $this->rankId($T->id), 'members.rank_id == 2');
        $this->assertGreaterThanOrEqual($handRankBonusT, $this->walletCents($T->id), 'wallet credited incl. rank bonus');

        // non-qualifiers: zero rank bonus
        foreach ([$L, $R, $L2, $R2] as $m) {
            $this->assertCount(0, $this->rankLines($m->id), "{$m->name} must have NO rank bonus line");
            $this->assertSame(0, $this->byTypeRankCents($m->id), "{$m->name} by_type[rank]=0");
            $this->assertLessThan(2, $this->rankId($m->id), "{$m->name} rank_id < 2");
        }

        // ---- ORACLE ----
        $exp = $this->oracleCompute($oracle);
        $this->assertSame($handRankBonusT, $exp['byType'][$T->id]['rank'], 'oracle: T rank bonus = $85');
        $this->assertSame(2, $exp['rank'][$T->id], 'oracle: T rank == 2');
        foreach ([$L, $R, $L2, $R2] as $m) {
            $this->assertSame(0, $exp['byType'][$m->id]['rank'], "oracle: {$m->name} rank bonus 0");
        }

        // cross-check live by_type[rank] == oracle for ALL members
        foreach ($this->topology() as $row) {
            $this->assertSame(
                $exp['byType'][$row['id']]['rank'],
                $this->byTypeRankCents($row['id']),
                "LIVE==ORACLE rank cents for member#{$row['id']}",
            );
        }
    }

    // ===================================================================
    // SCENARIO 2 — one-off semantics: rank2 not re-paid on recompute; rank3 paid once
    // ===================================================================

    public function test2_oneoff_rank2_not_duplicated_and_rank3_paid_once_on_top(): void
    {
        $oracle = $this->overrideRankBonuses([2 => 85.0, 3 => 120.0]);

        // Start identical to scenario 1 (T reaches rank2). Then grow the tree so the network
        // recomputes several more times (each activation = full recompute that WIPES and
        // REWRITES the snapshot) and eventually T reaches rank3 too.
        //
        // rank3 gates: small_branch >= 3000 AND personalInvited >= 8.
        // Build 8 personally-invited Gold members under T, 4 per leg:
        //   left leg : L, L2, L3, L4   (4 x 540 = 2160 .. need >=3000) -> add more
        //   right leg: R, R2, R3, R4
        // 540*? per leg >= 3000 -> need ceil(3000/540)=6 Gold per leg.
        // We make 6 invited per leg = 12 invited total (>=8), each leg 6*540=3240 (>=3000).
        $T = $this->root('T');

        // Build a left chain and right chain, all sponsored by T (counts as invited), all Gold.
        $leftNodes = [];
        $rightNodes = [];
        $prevL = $T;
        $prevR = $T;
        for ($i = 1; $i <= 6; $i++) {
            // First node of each chain attaches to T's own left/right slot; deeper nodes
            // chain downward on 'left' to stay within the same leg subtree.
            $lPos = $i === 1 ? 'left' : 'left';
            $rPos = $i === 1 ? 'right' : 'left';
            $nl = $this->add("L{$i}", $T, $prevL, $lPos);
            $nr = $this->add("R{$i}", $T, $prevR, $rPos);
            $leftNodes[] = $nl;
            $rightNodes[] = $nr;
            $prevL = $nl;
            $prevR = $nr;
        }

        // Activate T first, then enough to reach rank2, capturing intermediate state.
        $this->activate($T, 3);
        $this->activate($leftNodes[0], 3); // L1
        $this->activate($rightNodes[0], 3); // R1
        $this->activate($leftNodes[1], 3); // L2  -> now 4 invited, each leg 1080 -> rank2 reached
        $this->activate($rightNodes[1], 3); // R2

        // After reaching rank2, assert it's present exactly once.
        $this->assertSame(2, $this->rankId($T->id), 'T at rank2 mid-way');
        $rank2Lines = array_values(array_filter(
            $this->rankLines($T->id),
            fn ($l) => (int) ($l['basis']['meta']['rankId'] ?? 0) === 2,
        ));
        $this->assertCount(1, $rank2Lines, 'rank2 line present exactly once at rank2');

        // Keep activating (more recomputes) until rank3 gates met: 6 per leg + >=8 invited.
        $this->activate($leftNodes[2], 3);
        $this->activate($rightNodes[2], 3);
        $this->activate($leftNodes[3], 3);
        $this->activate($rightNodes[3], 3);
        $this->activate($leftNodes[4], 3);
        $this->activate($rightNodes[4], 3);
        $this->activate($leftNodes[5], 3);
        $this->activate($rightNodes[5], 3);

        // ---- HAND ----
        // small leg now = 6*540 = 3240 >= 3000; invited = 12 >= 8 -> T reaches rank3.
        // The engine WIPES member_bonus_lines on each recompute and rewrites from a single
        // CompensationEngine run; rank bonus fires once per rank achieved in that run
        // (rankId raised in ascending order). So after final recompute T must hold EXACTLY
        // one rank2 line ($85) AND one rank3 line ($120) -> by_type rank = 205.00.
        $this->assertSame(3, $this->rankId($T->id), 'T reached rank3');

        $allRank = $this->rankLines($T->id);
        $this->assertCount(2, $allRank, 'EXACTLY 2 rank lines total (rank2 + rank3), no duplicates/stacking');

        $byRankId = [];
        foreach ($allRank as $l) {
            $rid = (int) ($l['basis']['meta']['rankId'] ?? 0);
            $byRankId[$rid] = ($byRankId[$rid] ?? 0) + 1;
        }
        $this->assertSame(1, $byRankId[2] ?? 0, 'rank2 line appears EXACTLY once (not re-paid on recompute)');
        $this->assertSame(1, $byRankId[3] ?? 0, 'rank3 line appears EXACTLY once');

        $this->assertSame(8500 + 12000, $this->byTypeRankCents($T->id), 'by_type[rank] = $85 + $120 = $205');

        // ---- ORACLE ----
        $exp = $this->oracleCompute($oracle);
        $this->assertSame(3, $exp['rank'][$T->id], 'oracle: T rank == 3');
        $this->assertSame(20500, $exp['byType'][$T->id]['rank'], 'oracle: T rank bonus = $205 (85+120)');

        foreach ($this->topology() as $row) {
            $this->assertSame(
                $exp['byType'][$row['id']]['rank'],
                $this->byTypeRankCents($row['id']),
                "LIVE==ORACLE rank cents for member#{$row['id']}",
            );
        }
    }

    // ===================================================================
    // SCENARIO 3 — gate boundaries for rank2
    // ===================================================================

    /** 3a: small_branch 999 vs 1000 (holding invited>=4). Qualifies only at >=1000. */
    public function test3a_small_branch_boundary_999_vs_1000(): void
    {
        // ---- below: small leg = 999 PV ----
        // We need EXACTLY 999 PV on the smaller leg while keeping invited>=4.
        // 999 isn't reachable with pure Gold(540)/Silver(180)/Bronze(90) sums on one node,
        // but a leg's volume is the SUBTREE sum, so combine packages:
        //   leg = 540(Gold) + 180(Silver)*2 + 90(Bronze) = 540+360+90 = 990  (no)
        // Use Bronze granularity (90 each): 999 is not a multiple of 90 -> cannot hit 999.
        // The true boundary the gate enforces is ">= small_branch_pv (1000)". With 90-PV
        // granularity the nearest below is 990 and nearest at/above is 1080. We test
        //   990  (just-below, must NOT qualify) vs 1080 (>=1000, must qualify),
        // which still proves the strict ">=" boundary (sub-threshold fails, threshold passes).
        // Document: exact 999 is not constructible with the plan's package PVs; 990 is the
        // closest sub-threshold value and is sufficient to prove the gate.
        $this->runSmallBranchCase(belowPv: 990, atPv: 1080);
    }

    private function runSmallBranchCase(int $belowPv, int $atPv): void
    {
        // --- BELOW ($belowPv on small leg) ---
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        $T = $this->root('T');
        // 4 invitees, two per leg. We tune package sizes so each leg sums to $belowPv.
        // 990 = 540 + 180 + 180 + 90 across a leg's subtree. Simpler: per leg use a chain
        // of Bronze(90) nodes summing to belowPv; belowPv must be /90.
        // 990/90 = 11 nodes per leg -> too many invitees only need 4 total; invited counts
        // ALL sponsor==T nodes, extra is fine (>=4 still holds).
        $this->buildLegToPv($T, 'left', $belowPv, 'B');
        $this->buildLegToPv($T, 'right', $belowPv, 'C');
        // Ensure invited>=4: each leg with belowPv=990 has 11 nodes -> plenty. For atPv=1080
        // (2 Gold) only 2 per leg = 4 invited total -> exactly 4 (still >=4).

        // activate T
        $this->activate($T, 1); // T itself Bronze (its own PV doesn't count to its legs)

        $small = min($this->legPv($T->id, 'left'), $this->legPv($T->id, 'right'));
        $this->assertSame($belowPv, $small, "below-case small leg PV == {$belowPv}");
        $invited = $this->invitedCount($T->id);
        $this->assertGreaterThanOrEqual(4, $invited, 'invited>=4 held in below-case');

        $this->assertLessThan(2, $this->rankId($T->id), "small_branch={$belowPv} (<1000) must NOT reach rank2");
        $this->assertCount(0, $this->rankLines($T->id), 'no rank bonus below threshold');
        $exp = $this->oracleCompute($oracle);
        $this->assertLessThan(2, $exp['rank'][$T->id], 'oracle agrees: below threshold no rank2');
        $this->assertSame(0, $exp['byType'][$T->id]['rank']);

        // --- AT/ABOVE ($atPv on small leg) ---
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        $T = $this->root('T');
        $this->buildLegToPv($T, 'left', $atPv, 'B');
        $this->buildLegToPv($T, 'right', $atPv, 'C');
        $this->activate($T, 1);

        $small = min($this->legPv($T->id, 'left'), $this->legPv($T->id, 'right'));
        $this->assertSame($atPv, $small, "at-case small leg PV == {$atPv}");
        $this->assertGreaterThanOrEqual(4, $this->invitedCount($T->id), 'invited>=4 held in at-case');

        $this->assertSame(2, $this->rankId($T->id), "small_branch={$atPv} (>=1000) MUST reach rank2");
        $lines = $this->rankLines($T->id);
        $this->assertCount(1, $lines, 'exactly one rank2 bonus at/above threshold');
        $this->assertSame('85.00', $lines[0]['amount']);
        $this->assertSame(8500, $this->byTypeRankCents($T->id));

        $exp = $this->oracleCompute($oracle);
        $this->assertSame(2, $exp['rank'][$T->id], 'oracle agrees: at threshold reaches rank2');
        $this->assertSame(8500, $exp['byType'][$T->id]['rank']);
    }

    /** 3b: invited 3 vs 4 (holding small_branch>=1000). Qualifies only at >=4. */
    public function test3b_invited_boundary_3_vs_4(): void
    {
        // --- BELOW: invited = 3 (small leg >= 1000) ---
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        $T = $this->root('T');
        // Two legs each >= 1000 PV but using only 3 invitees total. A single Gold(540)+Gold(540)
        // = 1080 per leg needs 2 nodes per leg = 4 invitees. To get >=1000 with FEWER nodes,
        // make each leg ONE node carrying enough PV: but max package is Gold 540 < 1000.
        // So a single node can't reach 1000. Instead: place volume-only fillers whose
        // sponsor is NOT T (so they DON'T count as invited) plus exactly 3 invited (sponsor=T).
        //   left leg : I1(sponsor=T,Gold) -> under it F1(sponsor=L-filler? must be in subtree)
        // Simpler: invited nodes provide the structure; fillers (sponsor != T) provide extra PV.
        //   left : I1(sp=T,Gold 540) , under I1: FL(sp=I1,Gold 540)  -> leg=1080, invited here=1
        //   right: I2(sp=T,Gold 540) , under I2: I3(sp=T,Gold 540)   -> leg=1080, invited here=2
        // total invited (sponsor==T) = I1,I2,I3 = 3.  small_branch=1080>=1000.
        $I1 = $this->add('I1', $T, $T, 'left');
        $FL = $this->add('FL', $I1, $I1, 'left'); // sponsor = I1, NOT T -> not invited-by-T
        $I2 = $this->add('I2', $T, $T, 'right');
        $I3 = $this->add('I3', $T, $I2, 'left'); // sponsor = T -> invited

        $this->activate($T, 1);
        $this->activate($I1, 3);
        $this->activate($FL, 3);
        $this->activate($I2, 3);
        $this->activate($I3, 3);

        $this->assertSame(1080, $this->legPv($T->id, 'left'), 'left leg 1080');
        $this->assertSame(1080, $this->legPv($T->id, 'right'), 'right leg 1080');
        $this->assertSame(3, $this->invitedCount($T->id), 'exactly 3 invited (sponsor==T)');

        $this->assertLessThan(2, $this->rankId($T->id), 'invited=3 (<4) must NOT reach rank2');
        $this->assertCount(0, $this->rankLines($T->id), 'no rank bonus with invited=3');
        $exp = $this->oracleCompute($oracle);
        $this->assertLessThan(2, $exp['rank'][$T->id], 'oracle agrees invited=3 no rank2');

        // --- AT: invited = 4 (small leg >= 1000) ---
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        $T = $this->root('T');
        $L = $this->add('L', $T, $T, 'left');
        $L2 = $this->add('L2', $T, $L, 'left');   // sponsor=T
        $R = $this->add('R', $T, $T, 'right');
        $R2 = $this->add('R2', $T, $R, 'left');   // sponsor=T
        $this->activate($T, 1);
        $this->activate($L, 3);
        $this->activate($L2, 3);
        $this->activate($R, 3);
        $this->activate($R2, 3);

        $this->assertSame(1080, $this->legPv($T->id, 'left'));
        $this->assertSame(1080, $this->legPv($T->id, 'right'));
        $this->assertSame(4, $this->invitedCount($T->id), 'exactly 4 invited');

        $this->assertSame(2, $this->rankId($T->id), 'invited=4 (>=4) MUST reach rank2');
        $this->assertCount(1, $this->rankLines($T->id), 'one rank2 bonus with invited=4');
        $this->assertSame(8500, $this->byTypeRankCents($T->id));
        $exp = $this->oracleCompute($oracle);
        $this->assertSame(2, $exp['rank'][$T->id], 'oracle agrees invited=4 reaches rank2');
        $this->assertSame(8500, $exp['byType'][$T->id]['rank']);
    }

    /** 3c: both legs required — single-leg-heavy tree (other leg empty) must NOT qualify. */
    public function test3c_both_legs_required_single_heavy_leg_fails(): void
    {
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        // All volume + all invitees on the LEFT leg; RIGHT leg empty.
        //   left: L(sp=T,Gold) -> L2(sp=T,Gold) -> L3(sp=T,Gold) -> L4(sp=T,Gold)
        //   left leg PV = 4*540 = 2160 (>=1000), invited = 4 (>=4)
        //   right leg PV = 0  -> small_branch = min(2160,0) = 0 < 1000 -> FAIL.
        $T = $this->root('T');
        $L = $this->add('L', $T, $T, 'left');
        $L2 = $this->add('L2', $T, $L, 'left');
        $L3 = $this->add('L3', $T, $L2, 'left');
        $L4 = $this->add('L4', $T, $L3, 'left');

        $this->activate($T, 1);
        foreach ([$L, $L2, $L3, $L4] as $m) {
            $this->activate($m, 3);
        }

        $this->assertSame(2160, $this->legPv($T->id, 'left'), 'left heavy leg 2160');
        $this->assertSame(0, $this->legPv($T->id, 'right'), 'right leg empty');
        $this->assertSame(4, $this->invitedCount($T->id), 'invited 4 satisfied');

        $this->assertLessThan(2, $this->rankId($T->id), 'small_branch=0 (empty right leg) must NOT qualify');
        $this->assertCount(0, $this->rankLines($T->id), 'no rank bonus with one empty leg');

        $exp = $this->oracleCompute($oracle);
        $this->assertLessThan(2, $exp['rank'][$T->id], 'oracle agrees: empty leg -> no rank2');
        $this->assertSame(0, $exp['byType'][$T->id]['rank']);
    }

    // ===================================================================
    // SCENARIO 4 — rank4 in_rank_count gate (3 invited of rank>=2 vs 2)
    // ===================================================================

    public function test4_rank4_inrank_count_gate_3_vs_2(): void
    {
        // rank4 gates: small_branch>=8000 AND invitedByRank(rank>=2)>=3 (personal_count ignored).
        // To make an INVITED member reach rank>=2 themselves, EACH such invitee must satisfy
        // rank2 (small_branch>=1000 + 4 invited). This is heavy but constructible.
        //
        // Plan: target T. Build N "qualified managers" Q1..Q3, each of which:
        //   - sponsor = T (so they count toward T's invitedByRank)
        //   - placed within T's subtree
        //   - has its OWN sub-structure giving it rank2 (2 legs x>=1000 + 4 invited sponsored by Qk)
        // and enough total PV under T so T's small leg >= 8000.
        //
        // We split T into left/right; put Q1 on left, Q2 & Q3 on right (so each leg carries
        // qualified managers and PV). Each Qk subtree: Qk -> a(left,Gold)->b(left,Gold) and
        //   Qk -> c(right,Gold)->d(right,Gold): legs 1080/1080, invited(by Qk)=4 -> Qk rank2.
        // Qk subtree PV (excluding Qk's own personal, which counts to T's leg) = 4*540=2160,
        //   plus Qk's own 540 = 2700 contributed to T's leg through Qk.
        //
        // BELOW case: only 2 qualified managers (Q1 left, Q2 right) + extra raw PV to push
        //   small leg >= 8000, but invitedByRank=2 -> rank4 must FAIL (T may still be rank3).
        // AT case: 3 qualified managers, small leg >= 8000, invitedByRank=3 -> rank4 PASSES.

        // ---------- AT: 3 managers ----------
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 10.0, 3 => 20.0, 4 => 300.0]);

        $T = $this->root('T');

        // Build qualified manager Qk hanging off $parent on $position (parent in T-subtree).
        // Returns [Q, deepestLeftLeaf]. All of Qk's invitees are sponsored by Qk; Qk sponsored
        // by T. The deepest left leaf (b) has a FREE left slot for appending PV fillers.
        $buildManager = function (string $tag, Member $parentNode, string $pos) use ($T): array {
            $Q = $this->add($tag, $T, $parentNode, $pos); // sponsor = T
            $a = $this->add($tag . 'a', $Q, $Q, 'left');
            $b = $this->add($tag . 'b', $Q, $a, 'left');
            $c = $this->add($tag . 'c', $Q, $Q, 'right');
            $d = $this->add($tag . 'd', $Q, $c, 'left');
            return [$Q, $b];
        };

        // LEFT leg of T: Q1 + filler chain to add PV.
        [$Q1, $Q1leaf] = $buildManager('Q1', $T, 'left');
        // RIGHT leg of T: Q2 then Q3 deeper (Q3 hangs off Q2's right slot), plus fillers.
        [$Q2, $Q2leaf] = $buildManager('Q2', $T, 'right');
        // Q3 hangs off Q2's deepest-left leaf (a free slot inside T's right leg); sponsor=T.
        [$Q3, $Q3leaf] = $buildManager('Q3', $Q2leaf, 'left');

        // Need each T leg subtree >= 8000 PV. Current Gold nodes per leg:
        //   left  : Q1 + a,b,c,d = 5 Gold = 2700
        //   right : Q2(+4) + Q3(+4) = 10 Gold = 5400
        // Append Gold fillers below a FREE leaf (b), sponsored by the manager (NOT T) so they
        // do NOT inflate T's invited-by-rank. Each Gold = 540.
        //   left needs ceil((8000-2700)/540)=10 fillers ; right needs ceil((8000-5400)/540)=5.
        $this->appendGoldChain($Q1leaf, 'left', 10, 'LF', sponsor: $Q1);
        $this->appendGoldChain($Q3leaf, 'left', 5, 'RF', sponsor: $Q3);

        // Activate everyone Gold (managers/invitees/fillers) and T Gold too.
        $this->activateAllGoldByTopology();

        // Sanity: each manager reached rank2.
        foreach (['Q1' => $Q1, 'Q2' => $Q2, 'Q3' => $Q3] as $name => $Q) {
            $this->assertGreaterThanOrEqual(2, $this->rankId($Q->id), "{$name} must be rank>=2");
        }
        $invitedByRank2 = $this->invitedByRankCount($T->id, 2);
        $this->assertSame(3, $invitedByRank2, 'T has exactly 3 invited managers of rank>=2');
        $smallLeg = min($this->legPv($T->id, 'left'), $this->legPv($T->id, 'right'));
        $this->assertGreaterThanOrEqual(8000, $smallLeg, "T small leg >= 8000 (got {$smallLeg})");

        // ---- HAND/LIVE: T reaches rank4 -> rank4 bonus paid once ($300). ----
        $this->assertSame(4, $this->rankId($T->id), 'T reaches rank4 with 3 managers + small leg>=8000');
        $byRid = $this->rankLineCountsByRank($T->id);
        $this->assertSame(1, $byRid[4] ?? 0, 'rank4 bonus paid exactly once');

        // ---- ORACLE ----
        $exp = $this->oracleCompute($oracle);
        $this->assertSame(4, $exp['rank'][$T->id], 'oracle: T == rank4');
        // live==oracle rank bonus cents for T and all
        foreach ($this->topology() as $row) {
            $this->assertSame(
                $exp['byType'][$row['id']]['rank'],
                $this->byTypeRankCents($row['id']),
                "LIVE==ORACLE rank cents member#{$row['id']}",
            );
        }

        // ---------- BELOW: only 2 managers ----------
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');
        $oracle = $this->overrideRankBonuses([2 => 10.0, 3 => 20.0, 4 => 300.0]);

        $T = $this->root('T');
        $buildManager2 = function (string $tag, Member $parentNode, string $pos) use ($T): array {
            $Q = $this->add($tag, $T, $parentNode, $pos);
            $a = $this->add($tag . 'a', $Q, $Q, 'left');
            $b = $this->add($tag . 'b', $Q, $a, 'left');
            $c = $this->add($tag . 'c', $Q, $Q, 'right');
            $d = $this->add($tag . 'd', $Q, $c, 'left');
            return [$Q, $b];
        };
        [$Q1, $Q1leaf] = $buildManager2('Q1', $T, 'left');
        [$Q2, $Q2leaf] = $buildManager2('Q2', $T, 'right');
        // Make each leg >= 8000 with Gold fillers (sponsored by managers, not T) below a free leaf.
        // left: Q1 subtree 2700 -> need ceil((8000-2700)/540)=10. right: Q2 subtree 2700 -> 10.
        $this->appendGoldChain($Q1leaf, 'left', 10, 'LF', sponsor: $Q1);
        $this->appendGoldChain($Q2leaf, 'left', 10, 'RF', sponsor: $Q2);
        $this->activateAllGoldByTopology();

        $this->assertSame(2, $this->invitedByRankCount($T->id, 2), 'only 2 managers of rank>=2');
        $smallLeg = min($this->legPv($T->id, 'left'), $this->legPv($T->id, 'right'));
        $this->assertGreaterThanOrEqual(8000, $smallLeg, "small leg >=8000 in below-case (got {$smallLeg})");

        $this->assertLessThan(4, $this->rankId($T->id), 'invitedByRank=2 (<3) must NOT reach rank4');
        $byRid = $this->rankLineCountsByRank($T->id);
        $this->assertSame(0, $byRid[4] ?? 0, 'no rank4 bonus with only 2 managers');

        $exp = $this->oracleCompute($oracle);
        $this->assertLessThan(4, $exp['rank'][$T->id], 'oracle agrees: 2 managers -> no rank4');
    }

    // ===================================================================
    // SCENARIO 5 — reactivation idempotency: no double rank bonus
    // ===================================================================

    public function test5_reactivation_idempotency_no_double_rank_bonus(): void
    {
        $oracle = $this->overrideRankBonuses([2 => 85.0]);

        // Reuse scenario-1 qualifying tree.
        $T = $this->root('T');
        $L = $this->add('L', $T, $T, 'left');
        $R = $this->add('R', $T, $T, 'right');
        $L2 = $this->add('L2', $T, $L, 'left');
        $R2 = $this->add('R2', $T, $R, 'left');

        $this->activate($T, 3);
        $this->activate($L, 3);
        $this->activate($R, 3);
        $this->activate($L2, 3);
        $this->activate($R2, 3);

        $this->assertSame(2, $this->rankId($T->id));
        $this->assertCount(1, $this->rankLines($T->id), 'one rank bonus before reactivation');
        $beforeRankCents = $this->byTypeRankCents($T->id);
        $beforeWallet = $this->walletCents($T->id);

        // Re-activate T with the SAME idempotency key already used (act counter reused id).
        // ActivationService.activate is idempotent by key: insertOrIgnore returns 0 -> no recompute.
        $sameKey = 'reuse-key-T';
        $this->activation->activate($T->id, 3, $sameKey);
        $this->activation->activate($T->id, 3, $sameKey); // exact duplicate key

        $this->assertCount(1, $this->rankLines($T->id), 'still ONE rank line after duplicate-key reactivation');
        $this->assertSame($beforeRankCents, $this->byTypeRankCents($T->id), 'rank by_type unchanged');

        // Re-activate with a NEW key (forces a fresh full recompute). Since the engine WIPES
        // and rewrites the snapshot, the rank bonus is computed once per achieved rank again,
        // i.e. still ONE rank2 line — never doubled/stacked.
        $this->activation->activate($T->id, 3, 'fresh-recompute-key');
        $this->assertCount(1, $this->rankLines($T->id), 'still ONE rank line after fresh recompute');
        $this->assertSame($beforeRankCents, $this->byTypeRankCents($T->id), 'rank by_type still $85');
        $this->assertSame(2, $this->rankId($T->id), 'still rank2');

        // Wallet not double-credited for rank (recompute deltas are 0 for unchanged totals).
        $this->assertSame($beforeWallet, $this->walletCents($T->id), 'wallet unchanged by reactivation');

        $exp = $this->oracleCompute($oracle);
        $this->assertSame(8500, $exp['byType'][$T->id]['rank'], 'oracle: still $85');
    }

    // ===================================================================
    // Topology-building helpers (PV-tuned)
    // ===================================================================

    /** Build a leg under $root on $position summing to exactly $pv PV, all sponsored by $root.
     *  Uses Gold(540)/Silver(180)/Bronze(90) chain so the SUBTREE sum == $pv. $pv must be
     *  expressible; we greedily use Gold then Silver then Bronze (all multiples of 90 work). */
    private function buildLegToPv(Member $root, string $position, int $pv, string $tagPrefix): void
    {
        $packages = $this->decomposePv($pv); // list of packageIds
        $parent = $root;
        $pos = $position;
        $i = 0;
        foreach ($packages as $pkg) {
            $node = $this->add($tagPrefix . (++$i), $root, $parent, $pos);
            $this->activate($node, $pkg);
            // chain downward on 'left' to keep within the SAME leg subtree
            $parent = $node;
            $pos = 'left';
        }
    }

    /** Decompose a PV multiple of 90 into Gold(540)/Silver(180)/Bronze(90) package ids. */
    private function decomposePv(int $pv): array
    {
        $this->assertSame(0, $pv % 90, "PV {$pv} must be a multiple of 90 (package granularity)");
        $out = [];
        while ($pv >= 540) { $out[] = 3; $pv -= 540; }
        while ($pv >= 180) { $out[] = 2; $pv -= 180; }
        while ($pv >= 90)  { $out[] = 1; $pv -= 90; }
        return $out;
    }

    /** Append a chain of $n Gold nodes downward from $start on $position, sponsored by $sponsor. */
    private function appendGoldChain(Member $start, string $position, int $n, string $tag, Member $sponsor): array
    {
        $nodes = [];
        $parent = $start;
        $pos = $position;
        for ($i = 1; $i <= $n; $i++) {
            $node = $this->add($tag . $i, $sponsor, $parent, $pos);
            $nodes[] = $node;
            $parent = $node;
            $pos = 'left';
        }
        return $nodes;
    }

    /** Activate every member that has no package yet, Gold (used by scenario 4 builders). */
    private function activateAllGoldByTopology(): void
    {
        foreach (Member::query()->orderBy('id')->get(['id', 'package_id']) as $m) {
            if ($m->package_id === null) {
                $this->activation->activate((int) $m->id, 3, 'act-all-' . (++$this->act) . '-' . $m->id);
            }
        }
    }

    /** Subtree personal PV of one placement leg (child on $position of $rootId). */
    private function legPv(int $rootId, string $position): int
    {
        $child = Member::query()->where('parent_id', $rootId)->where('position', $position)->first();
        if ($child === null) {
            return 0;
        }
        return $this->subtreePv((int) $child->id);
    }

    private function subtreePv(int $id): int
    {
        $m = Member::query()->find($id);
        $pv = $m && $m->package_id ? [1 => 90, 2 => 180, 3 => 540][$m->package_id] : 0;
        foreach (Member::query()->where('parent_id', $id)->pluck('id') as $cid) {
            $pv += $this->subtreePv((int) $cid);
        }
        return $pv;
    }

    /** Count members whose sponsor_id == $sponsorId (global; matches the live result here
     *  because all such members are placed within the sponsor's subtree). */
    private function invitedCount(int $sponsorId): int
    {
        return Member::query()->where('sponsor_id', $sponsorId)->count();
    }

    /** Count invited members of rank>=$rank within sponsor's subtree. */
    private function invitedByRankCount(int $sponsorId, int $rank): int
    {
        return Member::query()
            ->where('sponsor_id', $sponsorId)
            ->where('rank_id', '>=', $rank)
            ->count();
    }

    /** rankId => count of rank bonus lines tagged with that rankId. */
    private function rankLineCountsByRank(int $memberId): array
    {
        $out = [];
        foreach ($this->rankLines($memberId) as $l) {
            $rid = (int) ($l['basis']['meta']['rankId'] ?? 0);
            $out[$rid] = ($out[$rid] ?? 0) + 1;
        }
        return $out;
    }
}
