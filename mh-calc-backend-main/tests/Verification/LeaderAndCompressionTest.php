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
 * LIVE end-to-end verification of LEADER bonus + RANK COMPRESSION, forcing the
 * paths random small trees almost never reach (sponsor rank >= 2).
 *
 * Each scenario:
 *   - builds a DETERMINISTIC tree with MANUAL placement (placement_mode=manual),
 *   - activates members in id order (engine recomputes whole net each time),
 *   - HAND-computes the expected leader cents,
 *   - asserts LIVE DB == hand,
 *   - asserts LIVE DB == ORACLE run over the real topology read back from DB.
 *
 * DB safety: setUp asserts we are on izigo_v_leader. Never touches izigo / izigo_test.
 *
 * Run:
 *   DB_DATABASE=izigo_v_leader php artisan migrate:fresh --force
 *   DB_DATABASE=izigo_v_leader php artisan test tests/Verification/LeaderAndCompressionTest.php
 */
class LeaderAndCompressionTest extends TestCase
{
    private MemberService $memberSvc;
    private ActivationService $activationSvc;
    private WalletService $walletSvc;

    protected function setUp(): void
    {
        parent::setUp();

        // HARD GUARD: we MUST be on the dedicated verification DB.
        $db = DB::connection()->getDatabaseName();
        $this->assertSame(
            'izigo_v_leader',
            $db,
            "Refusing to run: connected to '{$db}', expected 'izigo_v_leader'. "
            . 'Run with DB_DATABASE=izigo_v_leader.'
        );

        // Clean slate without re-migrating (migrate:fresh is run by the operator first).
        $this->wipe();

        $this->memberSvc = app(MemberService::class);
        $this->activationSvc = app(ActivationService::class);
        $this->walletSvc = app(WalletService::class);

        // All deterministic scenarios use MANUAL placement.
        PlanSetting::put('placement_mode', 'manual');
        $this->assertSame('manual', PlanSetting::get('placement_mode'), 'manual placement engaged');
    }

    private function wipe(): void
    {
        foreach ([
            'member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
            'activation_events', 'member_roles', 'members',
        ] as $table) {
            try {
                DB::table($table)->delete();
            } catch (\Throwable $e) {
                // ignore tables absent in some builds
            }
        }
    }

    // =====================================================================
    // Test infrastructure: register (manual placement) + activate helpers
    // =====================================================================

    private int $tg = 700000;

    /**
     * Register a member with MANUAL placement and return the live Member.
     * $sponsorRef/$parentRef are ref_codes (or null for the root).
     */
    private function reg(string $name, ?Member $sponsor, ?Member $parent, ?string $position): Member
    {
        $m = $this->memberSvc->registerTelegram(
            telegramId: ++$this->tg,
            name: $name,
            username: null,
            sponsorRef: $sponsor?->ref_code,
            language: null,
            parentRef: $parent?->ref_code,
            position: $position,
        );

        // Verify the engine placed it exactly where we asked (manual placement).
        if ($parent !== null) {
            $this->assertSame((int) $parent->id, (int) $m->parent_id, "{$name} parent");
            $this->assertSame($position, $m->position, "{$name} position");
        }
        if ($sponsor !== null) {
            $this->assertSame((int) $sponsor->id, (int) $m->sponsor_id, "{$name} sponsor");
        }

        return $m->refresh();
    }

    /**
     * Register $name SPONSORED BY $sponsor, placed at the FIRST FREE SLOT (BFS, left
     * before right) inside the placement subtree rooted at $under. $under MUST be in
     * $sponsor's subtree (manual placement requires manualParent ∈ sponsor subtree).
     * Default $under = $sponsor (place directly under the sponsor's own subtree).
     */
    private function regAuto(string $name, Member $sponsor, ?Member $under = null): Member
    {
        $under = $under ?? $sponsor;
        [$parentId, $position] = (new \Modules\Calculator\Services\Placement\PlacementTree())
            ->firstFreeSlot($under->refresh());
        $parent = Member::find($parentId);

        return $this->reg($name, $sponsor, $parent, $position);
    }

    private function activate(Member $m, int $packageId): void
    {
        $this->activationSvc->activate($m->id, $packageId, "v-leader-{$m->id}-{$packageId}");
    }

    // ---- read helpers ----------------------------------------------------

    private function liveType(int $memberId, string $type): int
    {
        $by = MemberEarning::query()->where('member_id', $memberId)->value('by_type') ?? [];
        return $this->toCents($by[$type] ?? '0');
    }

    private function liveLeaderLines(int $memberId): array
    {
        return MemberBonusLine::query()
            ->where('recipient_member_id', $memberId)
            ->where('type', 'leader')
            ->get()
            ->map(fn ($l) => [
                'amount' => $this->toCents((string) $l->amount),
                'level' => $l->basis['level'] ?? null,
                'sourceId' => $l->basis['sourceId'] ?? null,
            ])
            ->all();
    }

    private function liveRank(int $memberId): int
    {
        return (int) (Member::query()->where('id', $memberId)->value('rank_id') ?? 0);
    }

    /** All member ids in the placement subtree rooted at $rootId (inclusive). */
    private function subtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $queue = [$rootId];
        while ($queue !== []) {
            $p = array_shift($queue);
            foreach (Member::query()->where('parent_id', $p)->pluck('id') as $cid) {
                $ids[] = (int) $cid;
                $queue[] = (int) $cid;
            }
        }
        return $ids;
    }

    /**
     * Sum of leader cents credited to $sponsorId whose triggering binary was paid to
     * $binaryReceiverId — i.e. leader lines whose sourceId (binary initiator) lies in
     * $binaryReceiverId's placement subtree. (Engine sets a leader line's sourceId to the
     * binary INITIATOR; every initiator that completes $binaryReceiver's pair is inside
     * $binaryReceiver's subtree.)
     */
    private function leaderFromBinaryOf(int $sponsorId, int $binaryReceiverId): int
    {
        $sub = array_flip($this->subtreeIds($binaryReceiverId));
        return array_sum(array_map(
            fn ($l) => $l['amount'],
            array_filter(
                $this->liveLeaderLines($sponsorId),
                fn ($l) => isset($sub[$l['sourceId']])
            )
        ));
    }

    private function toCents(string|int|float|null $v): int
    {
        if ($v === null) {
            return 0;
        }
        return (int) round(((float) $v) * 100);
    }

    /** Read the full current topology from DB and run the independent oracle over it. */
    private function oracleNow(): array
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

    /**
     * Assert LIVE engine == ORACLE for every member & every bonus type + rank.
     * This is the strong differential check over the real built topology.
     */
    private function assertLiveMatchesOracle(string $label): void
    {
        $oracle = $this->oracleNow();
        $types = ['referral', 'binary', 'leader', 'rank'];

        foreach (array_keys($oracle['byType']) as $id) {
            foreach ($types as $t) {
                $exp = $oracle['byType'][$id][$t];
                $act = $this->liveType($id, $t);
                $this->assertSame(
                    $exp,
                    $act,
                    "[{$label}] member#{$id} {$t}: oracle {$exp}c != live {$act}c"
                );
            }
            $this->assertSame(
                $oracle['rank'][$id],
                $this->liveRank($id),
                "[{$label}] member#{$id} rank: oracle {$oracle['rank'][$id]} != live"
            );
        }
    }

    // =====================================================================
    // Shared sub-tree builder: lift a placement node to rank >= target.
    // =====================================================================

    /**
     * Build, under placement parent $under (on $position leg), a node S that will
     * reach rank>=2 (manager): it needs smallBranchVolume>=1000 PV (its placement
     * small leg) AND personalInvited>=4 (4 members sponsored by S inside S's subtree).
     *
     * Topology built under S (all GOLD = 540 PV personal, both placement legs filled
     * so the small leg accumulates well over 1000 PV):
     *
     *        S
     *       / \
     *      L   R          (L,R sponsored by S)             -> 2 personal invited
     *     / \   \
     *    LL LR   RR       (LL,LR,RR sponsored by S)        -> +3 personal invited = 5
     *
     * Personal PV (Gold=540) per leg:
     *   left leg subtree  = L+LL+LR = 540*3 = 1620 PV
     *   right leg subtree = R+RR    = 540*2 = 1080 PV
     *   small leg = 1080 PV >= 1000  -> rank2 volume gate OK
     *   personalInvited(S) = L,R,LL,LR,RR = 5 >= 4 -> rank2 invited gate OK
     *
     * S itself is given $sPackage. Returns the live S (already rank>=2 after we
     * activate the subtree). Activation order: S, then descendants by id.
     *
     * NOTE: ranks are evaluated with a temporal cutoff = current member id during the
     * full recompute, but since recompute reruns the WHOLE net from scratch on every
     * activation, after the LAST activation all gates see all nodes. We activate every
     * node so volumes exist.
     */
    private function buildRank2Sponsor(?Member $under, ?string $position, int $sPackage): array
    {
        // S is sponsored by $under (or is the root sponsor if $under is null root).
        $S = $this->reg('S', $under, $under, $under ? $position : null);

        $L  = $this->reg('S.L',  $S, $S,  'left');
        $R  = $this->reg('S.R',  $S, $S,  'right');
        $LL = $this->reg('S.LL', $S, $L,  'left');
        $LR = $this->reg('S.LR', $S, $L,  'right');
        $RR = $this->reg('S.RR', $S, $R,  'right');

        // Activate all GOLD so personal PV = 540 each.
        foreach ([$S, $L, $R, $LL, $LR, $RR] as $m) {
            $this->activate($m, 3);
        }
        // S package may differ from Gold: set it explicitly last.
        if ($sPackage !== 3) {
            $this->activate($S, $sPackage);
        }

        return compact('S', 'L', 'R', 'LL', 'LR', 'RR');
    }

    /**
     * Push an already-registered+activated node $A to rank4 by building its subtree:
     *   - LEFT leg: 3 managers (each rank>=2) SPONSORED BY A  => 3 invited rank>=2,
     *     all Gold => left-leg PV well over 8000.
     *   - RIGHT leg: Gold spine => right-leg PV >= 8000.
     * So A's small leg >= 8000 PV AND invited-rank>=2 count >= 3 => rank4.
     * A also ends with BOTH placement legs, so A can pair a binary.
     * Re-activates A last so the final recompute qualifies it.
     * Returns the RIGHT-leg anchor (A's small leg) for appending post-rank volume.
     */
    private function buildRank4Subtree(Member $A): Member
    {
        $AL = $this->reg($A->name . '.L', $A, $A, 'left');  // left anchor, sponsored by A
        $AR = $this->reg($A->name . '.R', $A, $A, 'right'); // right anchor, sponsored by A
        $this->activate($AL, 3);
        $this->activate($AR, 3);

        // 3 rank2 managers sponsored by A, placed inside A's LEFT-leg subtree ($AL).
        $makeManager = function (string $tag) use ($A, $AL): Member {
            $Q  = $this->regAuto($tag, $A, $AL);
            $L  = $this->regAuto("{$tag}.L",  $Q, $Q);
            $R  = $this->regAuto("{$tag}.R",  $Q, $Q);
            $LL = $this->regAuto("{$tag}.LL", $Q, $Q);
            $LR = $this->regAuto("{$tag}.LR", $Q, $Q);
            $RR = $this->regAuto("{$tag}.RR", $Q, $Q);
            foreach ([$Q, $L, $R, $LL, $LR, $RR] as $m) {
                $this->activate($m, 3);
            }
            return $Q->refresh();
        };
        $makeManager($A->name . 'Q1');
        $makeManager($A->name . 'Q2');
        $makeManager($A->name . 'Q3');

        // RIGHT leg Gold spine until right-leg subtree PV >= 8000 (anchor AR already 540).
        $pv = 540;
        $i = 0;
        while ($pv < 8000) {
            $n = $this->regAuto($A->name . 'GR' . $i, $AR, $AR);
            $this->activate($n, 3);
            $pv += 540;
            $i++;
        }

        $this->activate($A, 1); // re-activate A last for the final recompute
        return $AR->refresh();
    }

    /**
     * Push node $A to rank3 (NOT rank4): rank3 gate = small leg >= 3000 PV AND
     * personalInvited >= 8. We give A exactly 8 GOLD invitees (all rank0 leaves, so NONE
     * is rank>=2 => A cannot satisfy rank4's "3 invited rank>=2"), split 4/4 across two
     * legs, plus 2 Gold fillers per leg (sponsored by their parent, NOT A) so each leg's
     * subtree PV >= 3000. A ends with both legs => can pair a binary.
     */
    private function buildRank3Subtree(Member $A): Member
    {
        $AL = $this->reg($A->name . '.L', $A, $A, 'left');
        $AR = $this->reg($A->name . '.R', $A, $A, 'right');
        $this->activate($AL, 3);
        $this->activate($AR, 3);

        // 4 invitees (sponsored by A) inside each leg => 8 personal invites, all rank0 Gold.
        foreach (['L' => $AL, 'R' => $AR] as $tag => $anchor) {
            for ($k = 0; $k < 4; $k++) {
                $inv = $this->regAuto($A->name . "I{$tag}{$k}", $A, $anchor); // sponsor = A
                $this->activate($inv, 3);
            }
            // 2 fillers sponsored by the anchor (NOT A) to lift leg PV >= 3000.
            for ($k = 0; $k < 2; $k++) {
                $fil = $this->regAuto($A->name . "F{$tag}{$k}", $anchor, $anchor); // sponsor = anchor
                $this->activate($fil, 3);
            }
        }
        // Each leg: anchor + 4 invitees + 2 fillers = 7 Gold = 3780 PV >= 3000.
        // Make LEFT leg heavier so RIGHT stays the small leg with LEFT carryover for the
        // post-rank pair (mirrors the rank4 helper's geometry).
        for ($k = 0; $k < 6; $k++) {
            $fil = $this->regAuto($A->name . "FLX{$k}", $AL, $AL); // extra left fillers, sponsor=AL
            $this->activate($fil, 3);
        }

        $this->activate($A, 1); // re-activate A last
        return $AR->refresh();
    }

    /**
     * After $A has already reached a high rank, append fresh volume that PAIRS at $A so
     * that $A pays a binary bonus WHILE at that high rank (payout-time rank = high). This
     * is what makes the compression boundary observable: the engine checks A's rank AT
     * PAYOUT TIME (which equals the final rank for these late nodes), so A's own rank can
     * exceed a low-rank sponsor's by >= maxRankDiff at the moment its binary pays.
     *
     * $A's small leg is its RIGHT leg (the buildRank4/3 helpers pile the LEFT leg with
     * managers). Adding Gold to the RIGHT leg pairs against the large LEFT carryover, so
     * each appended node triggers a binary payout at $A. Returns the list of appended ids.
     *
     * @return int[] ids of the appended (binary-initiating) nodes
     */
    private function addPostRankPair(Member $A, Member $rightAnchor, int $count = 2): array
    {
        $ids = [];
        for ($k = 0; $k < $count; $k++) {
            // Sponsored by the right anchor (not A) so we don't change A's invite geometry.
            $n = $this->regAuto($A->name . 'POST' . $k, $rightAnchor, $rightAnchor);
            $this->activate($n, 3); // Gold 540 PV -> pairs at A against left carryover
            $ids[] = (int) $n->id;
        }
        return $ids;
    }

    // =====================================================================
    // SCENARIO 1 — LEADER at LEVEL 1, sponsor rank>=2, known package.
    // =====================================================================
    public function testScenario1_LeaderLevel1(): void
    {
        // Build root sponsor S at rank>=2 with SILVER package (pkg2 -> L1 leader 15%).
        $b = $this->buildRank2Sponsor(under: null, position: null, sPackage: 2);
        $S = $b['S'];

        // Now create A directly under S (placement) AND sponsored by S, so:
        //   - A's L1 sponsor = S (rank>=2, Silver).
        //   - A must PAY a binary bonus: A needs rank>=1 + both legs.
        // Place A on S's LEFT-of-L? No: A's own placement children give A's binary.
        // Build A with two Gold children so A pairs a binary.
        //
        //   Placement under S: S.L already has both children (LL,LR). Put A under S.R.left.
        //   We just need A somewhere with sponsor=S and its own two legs.
        $A = $this->reg('A', $S, $b['R'], 'left'); // A under R.left, sponsored by S
        $this->activate($A, 1); // A Bronze -> A needs rank>=1 to pay binary

        // A needs rank>=1: rank1 gate = personalInvited>=1. Give A one invitee.
        // And A needs BOTH placement legs with volume to pair a binary.
        $AL = $this->reg('A.L', $A, $A, 'left');  // sponsored by A => A.personalInvited=1
        $AR = $this->reg('A.R', $A, $A, 'right');
        $this->activate($AL, 1); // Bronze 90 PV
        $this->activate($AR, 1); // Bronze 90 PV
        // Re-activate A last so the final recompute sees its full subtree.
        $this->activate($A, 1);

        // ---- HAND COMPUTE ------------------------------------------------
        // A's legs: AL=90 PV, AR=90 PV. pair = min(90,90)=90 PV = 9000 cents.
        // A rank: personalInvited(A)=2 (AL,AR) >=1 -> rank1. binary% = 5%.
        // A binary bonus = 5% * 9000c = 450c ($4.50).
        // LEADER trigger: A pays binary 450c. A's sponsor chain L1 = S.
        //   S package=Silver(2), S rank>=2 -> leaderPercent[1][2][rank]=15%.
        //   leader(S) = 15% * 450c = 67.5 -> round half-up = 68c ($0.68).
        // Only ONE binary payout from A reaches S as level-1 sponsor.
        $expectedBinaryA = 450;
        $expectedLeaderS = (int) round(450 * 15 / 100); // 68

        // Sanity: S must actually be rank>=2.
        $this->assertGreaterThanOrEqual(2, $this->liveRank($S->id), 'S reached rank>=2');
        $this->assertGreaterThanOrEqual(1, $this->liveRank($A->id), 'A reached rank>=1');

        // ---- LIVE -------------------------------------------------------
        $liveBinaryA = $this->liveType($A->id, 'binary');
        $liveLeaderS = $this->liveType($S->id, 'leader');

        // ---- ORACLE -----------------------------------------------------
        $oracle = $this->oracleNow();
        $oracleLeaderS = $oracle['byType'][$S->id]['leader'];
        $oracleBinaryA = $oracle['byType'][$A->id]['binary'];

        fwrite(STDERR, sprintf(
            "\n[S1 L1] A binary: hand=%d live=%d oracle=%d | S leader: hand=%d live=%d oracle=%d\n",
            $expectedBinaryA, $liveBinaryA, $oracleBinaryA,
            $expectedLeaderS, $liveLeaderS, $oracleLeaderS
        ));

        $this->assertSame($expectedBinaryA, $liveBinaryA, 'S1: A binary live==hand');
        $this->assertSame($expectedLeaderS, $liveLeaderS, 'S1: S leader live==hand');
        $this->assertSame($expectedLeaderS, $oracleLeaderS, 'S1: S leader oracle==hand');
        $this->assertSame($expectedBinaryA, $oracleBinaryA, 'S1: A binary oracle==hand');

        // Leader line(s) to S must be level 1. NOTE: the engine sets a leader line's
        // sourceId = the BINARY INITIATOR (the member whose PV completed A's pair), NOT A
        // itself. For A's single pair the initiator is A's later leg (A.R). We assert the
        // line is level-1 and the total matches.
        $lines = $this->liveLeaderLines($S->id);
        $this->assertNotEmpty($lines, 'S1: at least one leader line to S');
        foreach ($lines as $l) {
            $this->assertSame(1, $l['level'], 'S1: leader line is level 1');
        }
        $this->assertSame(
            $expectedLeaderS,
            array_sum(array_map(fn ($l) => $l['amount'], $lines)),
            'S1: total leader to S == hand'
        );

        $this->assertLiveMatchesOracle('S1');
    }

    // =====================================================================
    // SCENARIO 2 — LEADER at LEVEL 2: Gold rank4 sponsor two sponsor-hops above A.
    // =====================================================================
    public function testScenario2_LeaderLevel2(): void
    {
        // L2 leader pays ONLY for Gold(pkg3) rank4 sponsor at level 2: 10%.
        // Chain needed: A --sponsor--> M --sponsor--> G, where G is Gold rank4.
        //
        // rank4 gate: small_branch_pv 8000 PV AND 3 personally-invited of rank>=2.
        // That requires 3 invitees each themselves rank>=2 (each needing 1000 small leg
        // + 4 invited). That's a big build but deterministic. We construct it.

        // ---- Build G (root) as Gold rank4 -------------------------------
        // G needs: small leg >= 8000 PV, and >=3 personally-invited at rank>=2.
        // Plan: G has two placement legs. Under each leg we put rank2 sub-managers
        // SPONSORED BY G, and pile Gold volume so small leg >= 8000 PV.
        //
        // We create three rank2 managers Q1,Q2,Q3 all SPONSORED BY G (=> invited-by-rank),
        // each Qi built via buildRank2Sponsor-style subtree to reach rank2, and place
        // them so BOTH of G's legs carry >= 8000 PV.

        $G = $this->reg('G', null, null, null); // root
        $this->activate($G, 3); // Gold

        // Two explicit legs directly under G (so each leg's subtree volume is isolated).
        // G.left holds the 3 rank2 managers; G.right is a Gold spine for the 8000 PV gate.
        $GL = $this->reg('GL', $G, $G, 'left');  // anchor of G's LEFT leg, sponsored by G
        $GR = $this->reg('GR', $G, $G, 'right'); // anchor of G's RIGHT leg, sponsored by G
        $this->activate($GL, 3);
        $this->activate($GR, 3);

        // Build a rank2 manager SPONSORED BY G, placed at the first free slot inside the
        // LEFT-leg subtree ($GL). Each manager + its 5 invitees = 6 Gold = 3240 PV, all in
        // G's left leg. 3 managers => 3*3240 + GL(540) > 8000 PV on the left leg.
        $makeManager = function (string $tag) use ($G, $GL): Member {
            $Q  = $this->regAuto($tag, $G, $GL);             // manager sponsored by G, in left leg
            $L  = $this->regAuto("{$tag}.L",  $Q, $Q);       // 5 invitees sponsored by Q, in Q subtree
            $R  = $this->regAuto("{$tag}.R",  $Q, $Q);
            $LL = $this->regAuto("{$tag}.LL", $Q, $Q);
            $LR = $this->regAuto("{$tag}.LR", $Q, $Q);
            $RR = $this->regAuto("{$tag}.RR", $Q, $Q);
            foreach ([$Q, $L, $R, $LL, $LR, $RR] as $m) {
                $this->activate($m, 3); // Gold each
            }
            return $Q->refresh();
        };
        $Q1 = $makeManager('Q1');
        $Q2 = $makeManager('Q2');
        $Q3 = $makeManager('Q3');

        // RIGHT leg: Gold spine under $GR until the right-leg subtree PV >= 8000.
        // GR already = 540; add fillers (sponsored by their parent, placed at free slots in
        // GR's subtree) until >= 8000.
        $rightPv = 540; // GR
        $i = 0;
        while ($rightPv < 8000) {
            $node = $this->regAuto('GRF' . $i, $GR, $GR); // sponsored by GR, inside GR subtree
            $this->activate($node, 3);
            $rightPv += 540;
            $i++;
        }

        // Re-activate G LAST so the final full recompute sees the whole tree and
        // qualifies G to rank4.
        $this->activate($G, 3);

        // Managers must each be rank>=2 (so they count toward G's "3 invited rank>=2").
        $this->assertGreaterThanOrEqual(2, $this->liveRank($Q1->id), 'Q1 >= rank2');
        $this->assertGreaterThanOrEqual(2, $this->liveRank($Q2->id), 'Q2 >= rank2');
        $this->assertGreaterThanOrEqual(2, $this->liveRank($Q3->id), 'Q3 >= rank2');
        $this->assertSame(4, $this->liveRank($G->id), 'G reached rank4 (Gold)');

        // ---- Build A -> M -> G sponsor chain, A pays a binary ------------
        // M sponsored by G (so M's sponsor is G); A sponsored by M (so A.sponsor=M,
        // A.sponsor.sponsor=G => G is A's L2 sponsor).
        // A must pay a binary (rank>=1 + both legs).
        $M = $this->regAuto('M', $G, $GR); // M sponsored by G, placed inside G's subtree
        $this->activate($M, 1); // M Bronze (irrelevant to L2 leader pct which keys on G)

        $A = $this->regAuto('A', $M, $M); // A sponsored by M, inside M's subtree
        $this->activate($A, 1); // A Bronze
        $AL = $this->reg('A.L', $A, $A, 'left');
        $AR = $this->reg('A.R', $A, $A, 'right');
        $this->activate($AL, 1); // Bronze 90 PV
        $this->activate($AR, 1); // Bronze 90 PV
        $this->activate($A, 1);  // re-activate A last

        // ---- HAND COMPUTE ----------------------------------------------
        // A legs AL=90, AR=90 PV. pair=90 PV=9000c. A rank1 (2 invitees). binary 5%.
        // A binary = 5% * 9000c = 450c.
        // LEADER from A's binary:
        //   L1 sponsor = M (Bronze, but rank? M has no qualifying subtree -> rank0).
        //     leaderPercent[1][bronze=1][rank0] -> not in table -> 0. (M gets 0 leader.)
        //   L2 sponsor = G (Gold pkg3, rank4): leaderPercent[2][3][4] = 10%.
        //     leader(G) += 10% * 450c = 45c ($0.45).
        // BUT rank-compression may apply: between A and G on the SPONSOR chain sits M.
        //   M.rank (0) - G.rank (4) is negative -> no compression from M.
        //   Also engine scans A itself: A.rank(1) - G.rank(4) negative. No compression.
        // So G gets the full 45c.
        $expectedBinaryA = 450;
        $expectedLeaderG_fromA = (int) round(450 * 10 / 100); // 45 (the L2 leader to G from A's pair)

        $liveBinaryA = $this->liveType($A->id, 'binary');
        // NOTE: G's TOTAL leader includes L1 leader from G's direct sponsorees' binaries
        // (Q1/Q2/Q3/M). So we cannot compare the total to 45. Instead we (a) check the
        // total live==oracle (differential), and (b) isolate A's L2 contribution by hand.
        $liveLeaderG_total = $this->liveType($G->id, 'leader');
        $liveLeaderM = $this->liveType($M->id, 'leader');

        $oracle = $this->oracleNow();
        $oracleLeaderG_total = $oracle['byType'][$G->id]['leader'];

        fwrite(STDERR, sprintf(
            "\n[S2 L2] A binary: hand=%d live=%d | G leader TOTAL: live=%d oracle=%d | A's L2 contribution hand=%d | M leader=%d\n",
            $expectedBinaryA, $liveBinaryA,
            $liveLeaderG_total, $oracleLeaderG_total, $expectedLeaderG_fromA, $liveLeaderM
        ));

        $this->assertSame($expectedBinaryA, $liveBinaryA, 'S2: A binary live==hand');
        $this->assertSame($oracleLeaderG_total, $liveLeaderG_total, 'S2: G leader total live==oracle');
        $this->assertSame(0, $liveLeaderM, 'S2: M (bronze rank0) gets 0 leader');
        $expectedLeaderG = $expectedLeaderG_fromA; // used by the line-existence check below

        // The L2 leader line(s) to G from A's binary subtree must be level 2.
        // (sourceId = binary initiator that completed A's pair, not A itself.)
        // G may also receive L2 leader from OTHER binary payouts in the big build; we
        // isolate A's contribution by the level-2 lines whose amount matches A's pair.
        // The robust live==hand check is the by_type total via oracle below; here we
        // assert there exists a level-2 leader line to G of the expected size.
        $linesG = $this->liveLeaderLines($G->id);
        $l2 = array_values(array_filter($linesG, fn ($l) => $l['level'] === 2 && $l['amount'] === $expectedLeaderG));
        $this->assertNotEmpty($l2, 'S2: a level-2 leader line to G of the expected amount exists');

        $this->assertLiveMatchesOracle('S2');
    }

    // =====================================================================
    // SCENARIO 3 — RANK COMPRESSION: sponsor skipped (diff>=2) vs near-miss (diff=1).
    // =====================================================================
    public function testScenario3_RankCompression(): void
    {
        // Setup: sponsor chain A --sp--> X --sp--> S.
        //   S is the L2 sponsor we test for compression.
        //   X is the intermediate node on the sponsor chain between A and S.
        // Compression rule (engine): skip S if some node on the chain (A..X, EXCLUDING S)
        //   has (node.rank - S.rank) >= maxRankDiff(2).
        //
        // We want X.rank - S.rank >= 2 to SKIP S; and a near-miss where diff==1 -> NOT skip.
        //
        // To make S the L2 sponsor of A and have an intermediate X with controllable rank,
        // we need A.sponsor = X, X.sponsor = S. We set:
        //   - S = Gold rank4 (so leaderPercent[2][3][4]=10% would pay if not compressed).
        //   - X rank tuned: rank>=2 (skip, diff = X(>=... ) need X.rank - 4? No.)
        //
        // Wait: diff = node.rank - S.rank. S is rank4. For diff>=2 we'd need X.rank>=6,
        // impossible. So to get compression with S as the HIGH-rank sponsor we instead
        // make S a LOW rank and X a HIGH rank. But then S's leaderPercent is 0 anyway.
        //
        // The meaningful compression test: the sponsor that WOULD pay is the one being
        // skipped. So that sponsor must itself be rank>=2 (to have nonzero leader%) AND
        // be outranked by an intermediate by >=2. rank2 sponsor + rank4 intermediate:
        //   diff = 4 - 2 = 2 >= 2 -> SKIP.  (compression)
        // Near miss: rank3 intermediate: diff = 3 - 2 = 1 < 2 -> NOT skipped.
        //
        // So: S = SILVER rank2 (L1 leader 15% if it pays). X = the intermediate.
        // Make S the L1 sponsor of A (level 1), X between... but X must be on the chain
        // BETWEEN A and S. For level-1 sponsor S, there is NO node strictly between A and
        // S (S = A.sponsor directly). So to have an intermediate we must test at LEVEL >=2.
        //
        // Put S at LEVEL 2: A.sponsor = X, X.sponsor = S. S=Silver rank2.
        //   leaderPercent[2][silver=2][rank2] -> table has only [2][3][4]. So L2 silver=0!
        // Hmm: at level 2 only Gold rank4 pays. A Silver rank2 sponsor at L2 pays 0 anyway,
        // so compression wouldn't be observable via amount.
        //
        // RESOLUTION: To observe compression we need the skipped sponsor to have a NONZERO
        // leader% at its level. Only Gold rank4 pays at L2. So make the SKIPPED sponsor =
        // Gold rank4 at L2, and the intermediate X outrank it by >=2 -> impossible (max 4).
        // Therefore at L2 compression can never zero-out a paying sponsor by an intermediate.
        //
        // => Compression that changes a PAID amount is only observable at LEVEL 1 if there
        // were an intermediate — but L1 has none. The ENGINE still SCANS 'A' itself though
        // (see hasHigherRankInChain starts at binaryReceiver=A). So the ONLY way an L1
        // sponsor gets compressed is if A ITSELF outranks the sponsor by >=2.
        //   S = Silver rank2 (would pay L1 15%). A.rank - S.rank >= 2 => A.rank >= 4.
        //   If A is rank4 and S is rank2 -> diff=2 -> ENGINE compresses S (skips it).
        //   ORACLE: strictly-between A and S => empty set => NOT compressed => S pays.
        // This is EXACTLY the boundary divergence (Task 4). We test it explicitly there.
        //
        // For a CLEAN compression test independent of the boundary, use an intermediate
        // that is NEITHER A nor S: test at LEVEL where an intermediate exists and the
        // skipped sponsor pays. Since L2 paying sponsor must be Gold rank4 (uncompressable),
        // we cannot. So Scenario 3's compression-by-intermediate is exercised with the
        // skipped sponsor at L1 but where the intermediate == A boundary is what matters.
        //
        // We therefore IMPLEMENT Scenario 3 as: a controllable intermediate X that, per the
        // ENGINE's scan set {A, ..., up to before S}, can outrank S. Build:
        //   A.sponsor = S (L1). Insert NOTHING between (none possible at L1). Use A as the
        //   "intermediate" the engine scans. Skip-case: A rank4, S rank2 (diff 2 -> engine skip).
        //   Near-miss: A rank3, S rank2 (diff 1 -> not skipped).
        // Build the high-rank A via the rank2-sponsor builder pattern, then push to rank4.

        // ----- Build S = Silver rank2 (root) -----
        $sb = $this->buildRank2Sponsor(under: null, position: null, sPackage: 2);
        $S = $sb['S'];
        $this->assertSame(2, $this->liveRank($S->id), 'S is rank2');
        $this->assertSame(2, (int) Member::find($S->id)->package_id, 'S is Silver');

        // ----- Build A under S (placement+sponsor), pushed to rank4 -----
        // A must be sponsored by S (so S is A's L1 leader sponsor) AND reach rank4.
        // rank4 needs small leg 8000 PV + 3 invited rank>=2. Build A's subtree.
        $A = $this->reg('A', $S, $sb['R'], 'left'); // A sponsored by S, placed under S.R.left
        $this->activate($A, 1); // A Bronze (package irrelevant to its rank gates)
        $AR = $this->buildRank4Subtree($A);
        $this->assertSame(4, $this->liveRank($A->id), 'A reached rank4');

        // KEY: ranks are taken AT PAYOUT TIME. To get A's rank above S's by >=2 *while A
        // pays a binary*, we append fresh paired volume AFTER A is rank4 so the new binary
        // payouts occur with A already rank4 (payout-time rank = 4). Each POST node pairs
        // at A -> binary -> leader to S(rank2).
        $postIds = $this->addPostRankPair($A, $AR, count: 2);

        // ---- ENGINE compression of these POST binaries: scan starts at binaryReceiver=A.
        // A.rank(4) - S.rank(2) = 2 >= 2 -> SKIP S. So leader to S from POST initiators = 0.
        // ---- ORACLE: compressedOut(A, S) walks from A.sponsor(=S); first step hits S and
        // stops => empty "strictly between" set => NOT compressed => S WOULD be paid.
        $engineLeaderS_fromPost = 0;
        foreach ($this->liveLeaderLines($S->id) as $l) {
            if (in_array($l['sourceId'], $postIds, true)) {
                $engineLeaderS_fromPost += $l['amount'];
            }
        }

        // Oracle would pay S for the POST binaries; isolate the gap as engine-vs-oracle total.
        $oracle = $this->oracleNow();
        $oracleLeaderS_total = $oracle['byType'][$S->id]['leader'];
        $engineLeaderS_total = $this->liveType($S->id, 'leader');
        $gap = $oracleLeaderS_total - $engineLeaderS_total;

        fwrite(STDERR, sprintf(
            "\n[S3 SKIP] A=rank%d S=rank2 silver. POST binaries pay while A is rank4.\n"
            . "  ENGINE leader(S from POST initiators) = %d c  (expect 0 => S COMPRESSED, scan includes A)\n"
            . "  leader(S) total: engine=%d c oracle=%d c  GAP=%d c (oracle pays the compressed-away POST leader)\n",
            $this->liveRank($A->id), $engineLeaderS_fromPost,
            $engineLeaderS_total, $oracleLeaderS_total, $gap
        ));

        $this->assertSame(0, $engineLeaderS_fromPost, 'S3 SKIP: S compressed out of POST binaries (engine)');
        $this->assertGreaterThan(0, $gap, 'S3 SKIP: oracle (strictly-between) pays S the compressed-away leader');

        // ---------- NEAR MISS (diff = 1, NOT skipped) ----------
        // Rebuild with A at rank3 instead of rank4. rank3 gate: 3000 PV small leg + 8 invited.
        $this->wipe();
        PlanSetting::put('placement_mode', 'manual');

        $sb2 = $this->buildRank2Sponsor(under: null, position: null, sPackage: 2);
        $S2 = $sb2['S'];

        $A2 = $this->reg('A2', $S2, $sb2['R'], 'left');
        $this->activate($A2, 1);
        $AR2 = $this->buildRank3Subtree($A2);

        $rankA2 = $this->liveRank($A2->id);
        fwrite(STDERR, sprintf("\n[S3 NEAR] A2 rank=%d (want 3), S2 rank=%d (want 2)\n", $rankA2, $this->liveRank($S2->id)));
        $this->assertSame(3, $rankA2, 'S3 near-miss: A2 is rank3 (diff to S2 rank2 = 1)');
        $this->assertSame(2, $this->liveRank($S2->id), 'S3 near-miss: S2 rank2');

        // POST binaries pay while A2 is rank3. diff = A2(3) - S2(2) = 1 < 2 -> NOT skipped.
        $postIds2 = $this->addPostRankPair($A2, $AR2, count: 2);
        $leaderS2_fromPost = 0;
        foreach ($this->liveLeaderLines($S2->id) as $l) {
            if (in_array($l['sourceId'], $postIds2, true)) {
                $leaderS2_fromPost += $l['amount'];
            }
        }
        fwrite(STDERR, sprintf("[S3 NEAR] engine leader(S2 from POST initiators)=%dc (expect > 0, NOT compressed, diff=1)\n", $leaderS2_fromPost));
        $this->assertGreaterThan(0, $leaderS2_fromPost, 'S3 near-miss: S2 NOT compressed (diff=1) — paid for POST binaries');

        // Engine and oracle AGREE here (A2 diff to S2 < 2, no boundary effect): full live==oracle.
        $this->assertLiveMatchesOracle('S3-near');
    }

    // =====================================================================
    // SCENARIO 4 — COMPRESSION BOUNDARY: strictly-between vs inclusive-of-A.
    // =====================================================================
    public function testScenario4_CompressionBoundary(): void
    {
        // The engine's hasHigherRankInChain($receiver=sponsor, $binaryReceiver=A) loop:
        //   $chainNode = A;  while ($chainNode) { if (chainNode.rank - sponsor.rank >= 2) skip;
        //                                         chainNode = chainNode.sponsor;
        //                                         if (chainNode==null || chainNode.id==sponsor.id) break; }
        // => the scan set INCLUDES A itself, walks up, EXCLUDES the sponsor.
        // The ORACLE compressedOut() starts at A.sponsor (EXCLUDES A), excludes sponsor.
        // => "strictly between A and sponsor".
        //
        // DISTINGUISHING CASE: L1 sponsor S (rank2 silver, would pay 15%), and A itself
        // rank4. There is NO node strictly between A and S (S=A.sponsor). So:
        //   ENGINE scans {A}: A.rank(4) - S.rank(2) = 2 >= 2  -> COMPRESS (S gets 0).
        //   ORACLE scans {} (strictly between): nothing -> NOT compressed (S gets 15%).
        // The two MUST diverge. Per SPEC ("rank compression: skip a sponsor if some node
        // strictly between A and the sponsor outranks it by >=2"), the ORACLE matches the
        // spec text; the ENGINE includes A, which is BROADER than the spec text.

        $sb = $this->buildRank2Sponsor(under: null, position: null, sPackage: 2);
        $S = $sb['S'];
        $this->assertSame(2, $this->liveRank($S->id), 'S rank2 silver');

        // A sponsored by S, pushed to rank4, THEN add POST volume that pairs while A=rank4.
        $A = $this->reg('A', $S, $sb['R'], 'left');
        $this->activate($A, 1);
        $AR = $this->buildRank4Subtree($A);
        $this->assertSame(4, $this->liveRank($A->id), 'A rank4');
        $postIds = $this->addPostRankPair($A, $AR, count: 2);

        // ---- ENGINE: leader to S from the POST binaries (paid while A.rank=4). ----
        // hasHigherRankInChain($receiver=S, $binaryReceiver=A): chainNode STARTS at A.
        //   A.rank(4) - S.rank(2) = 2 >= maxRankDiff(2) => returns TRUE => S COMPRESSED.
        // So S gets 0 leader from EACH of these POST binaries.
        $engineLeaderS_fromPost = 0;
        foreach ($this->liveLeaderLines($S->id) as $l) {
            if (in_array($l['sourceId'], $postIds, true)) {
                $engineLeaderS_fromPost += $l['amount'];
            }
        }

        // ---- ORACLE: compressedOut($A, $S) starts at A.sponsor (=S); loop guard
        //   ($node !== $sponsorId) is immediately false => empty "strictly between" set =>
        //   NOT compressed => S WOULD be paid 15% of each POST binary. ----
        // We confirm a per-payout HAND value: each POST node = Gold 540 PV pairs 540 PV =
        //   binary 5% * 54000c = 2700c; leader to S(rank2 silver) = 15% * 2700c = 405c.
        $oracle = $this->oracleNow();
        $oracleLeaderS_total = $oracle['byType'][$S->id]['leader'];
        $engineLeaderS_total = $this->liveType($S->id, 'leader');
        $gap = $oracleLeaderS_total - $engineLeaderS_total;

        fwrite(STDERR, sprintf(
            "\n[S4 BOUNDARY] L1 sponsor S=rank2 silver, A=rank4, NO node STRICTLY BETWEEN A and S.\n"
            . "  POST binaries pay while A.rank=4 (payout-time rank).\n"
            . "  ENGINE leader(S from POST initiators) = %d c   (scan INCLUDES A => A.rank4 - S.rank2 = 2 >= 2 => COMPRESSED)\n"
            . "  ENGINE leader(S) total = %d c ; ORACLE leader(S) total = %d c ; GAP = %d c\n"
            . "  => The GAP is the leader the ORACLE (and the SPEC's 'strictly between' text)\n"
            . "     pays S for the POST binaries, but the ENGINE compresses away because its\n"
            . "     scan is INCLUSIVE-OF-A. FINDING: engine compression includes the binary\n"
            . "     receiver A; spec text says STRICTLY BETWEEN. DIVERGENCE confirmed.\n",
            $engineLeaderS_fromPost, $engineLeaderS_total, $oracleLeaderS_total, $gap
        ));

        // Engine compresses S out of the POST binaries (scan includes A).
        $this->assertSame(0, $engineLeaderS_fromPost, 'S4: ENGINE compresses S (scan INCLUDES binary receiver A)');
        // Oracle / spec text (strictly-between) pays S => oracle total strictly larger.
        $this->assertGreaterThan(
            $engineLeaderS_total,
            $oracleLeaderS_total,
            'S4: BOUNDARY FINDING — oracle/spec (strictly-between) pays; engine (inclusive of A) does not'
        );
    }

    // =====================================================================
    // SCENARIO 5 — IDEMPOTENCY: re-activate, leader not double-counted.
    // =====================================================================
    public function testScenario5_Idempotency(): void
    {
        // Small leader-producing tree (reuse scenario-1 shape).
        $b = $this->buildRank2Sponsor(under: null, position: null, sPackage: 2);
        $S = $b['S'];

        $A = $this->reg('A', $S, $b['R'], 'left');
        $this->activate($A, 1);
        $AL = $this->reg('A.L', $A, $A, 'left');
        $AR = $this->reg('A.R', $A, $A, 'right');
        $this->activate($AL, 1);
        $this->activate($AR, 1);
        $this->activate($A, 1);

        $leaderBefore = $this->liveType($S->id, 'leader');
        $linesBefore = count($this->liveLeaderLines($S->id));
        $this->assertGreaterThan(0, $leaderBefore, 'S5: leader exists before re-activation');

        // Re-activate A with the SAME idempotency key used by activate() helper:
        // activate() builds key "v-leader-{id}-{pkg}". Calling again with same args
        // hits the SAME key => insertOrIgnore => recompute SKIPPED (idempotent).
        $this->activate($A, 1); // same key -> no recompute
        $leaderAfterSameKey = $this->liveType($S->id, 'leader');
        $linesAfterSameKey = count($this->liveLeaderLines($S->id));

        $this->assertSame($leaderBefore, $leaderAfterSameKey, 'S5: same-key re-activate does not change leader');
        $this->assertSame($linesBefore, $linesAfterSameKey, 'S5: same-key re-activate does not add lines');

        // A genuinely NEW activation event (different key) DOES recompute, but the snapshot
        // is REPLACED (delete + recreate), so leader is NOT doubled — it stays identical
        // because the topology/packages are unchanged.
        $this->activationSvc->activate($A->id, 1, 'v-leader-rekey-' . $A->id);
        $leaderAfterRekey = $this->liveType($S->id, 'leader');
        $linesAfterRekey = count($this->liveLeaderLines($S->id));

        fwrite(STDERR, sprintf(
            "\n[S5 IDEMP] leader(S): before=%dc sameKey=%dc newKey(recompute)=%dc | lines before=%d after=%d\n",
            $leaderBefore, $leaderAfterSameKey, $leaderAfterRekey, $linesBefore, $linesAfterRekey
        ));

        $this->assertSame($leaderBefore, $leaderAfterRekey, 'S5: recompute REPLACES snapshot, leader not doubled');
        $this->assertSame($linesBefore, $linesAfterRekey, 'S5: recompute does not duplicate leader lines');

        $this->assertLiveMatchesOracle('S5');
    }
}
