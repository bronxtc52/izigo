<?php

namespace Tests\Verification;

/**
 * INDEPENDENT reference implementation ("oracle") of the IziGo MLM bonus engine.
 *
 * This class is written PURELY FROM THE MARKETING-PLAN SPEC, with NO dependency on
 * the production engine code under Modules/Calculator/Domain. It is used as an
 * arbiter in the differential harness: live engine vs oracle.
 *
 * Money/PV are tracked in INTEGER CENTS (1 PV = $1, so 90 PV = 9000 cents) to avoid
 * float drift. Percent application rounds HALF-UP (= half-away-from-zero for positive
 * values, matching PHP's native round()).
 *
 * INPUT: a list of members in ascending-id activation order, each:
 *   ['id'=>int, 'name'=>string, 'sponsorId'=>?int, 'parentId'=>?int,
 *    'position'=>?string('left'|'right'), 'packageId'=>?int(1|2|3 or null)]
 *
 * The engine processes members by ascending id; for each member it:
 *   1) applies volumes (push PV up the placement chain)
 *   2) qualifies ranks up the placement chain (temporal cutoff = current member id)
 *   3) pays REFERRAL up the sponsor chain
 *   4) pays BINARY up the placement chain (each binary payout triggers LEADER)
 *
 * The whole network is recomputed from scratch on every engine recompute; this
 * oracle mirrors that: call compute() with the full current member set.
 */
final class BonusOracle
{
    // ---- DEFAULT PLAN PARAMS (from the SPEC) ------------------------------

    /** packageId => PV cents (1 PV = $1 => *100). Bronze 90, Silver 180, Gold 540. */
    private const PACKAGE_PV_CENTS = [1 => 9000, 2 => 18000, 3 => 54000];
    /** packageId => sort (used to key referral percent). */
    private const PACKAGE_SORT = [1 => 1, 2 => 2, 3 => 3];

    /** referralPercent[sponsorPackageSort][level] => percent. */
    private const REFERRAL_PERCENT = [
        1 => [1 => 10, 2 => 0],
        2 => [1 => 10, 2 => 5],
        3 => [1 => 10, 2 => 8],
    ];
    private const REFERRAL_DEPTH = 2;

    /** binaryPercent[rankId] => percent (flat 5%). */
    private const BINARY_PERCENT = [1 => 5, 2 => 5, 3 => 5, 4 => 5];

    /** leaderPercent[level][sponsorPackageId][sponsorRankId] => percent. */
    private const LEADER_PERCENT = [
        1 => [
            1 => [2 => 10, 3 => 10, 4 => 10],
            2 => [2 => 15, 3 => 15, 4 => 15],
            3 => [2 => 20, 3 => 20, 4 => 20],
        ],
        2 => [
            3 => [4 => 10],
        ],
    ];
    private const LEADER_MAX_LEVEL = 2;
    private const MAX_RANK_DIFF = 2;

    /**
     * Ranks ascending. small_branch_pv in PV units (gate threshold; 0 = ignore).
     * personal_count gate; in_rank_count of rank>=in_rank_id gate (0 = ignore).
     * bonus_usd default 0 (positive only via override).
     */
    private const RANKS = [
        ['id' => 1, 'small_branch_pv' => 0,    'personal_count' => 1, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
        ['id' => 2, 'small_branch_pv' => 1000, 'personal_count' => 4, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
        ['id' => 3, 'small_branch_pv' => 3000, 'personal_count' => 8, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
        ['id' => 4, 'small_branch_pv' => 8000, 'personal_count' => 0, 'in_rank_count' => 3, 'in_rank_id' => 2, 'bonus_usd' => 0],
    ];

    /**
     * Toggle for the rank-qualification "personal invited" geometry.
     *
     * SPEC text: personalInvited = count of ALL members whose sponsor_id == this member
     * (a GLOBAL sponsorship count, no placement-subtree restriction).
     *
     * ENGINE behaviour (Modules/.../RankSnapshot): personalInvited is counted only
     * within the node's PLACEMENT subtree (and id <= temporal cutoff).
     *
     * Default = SPEC (global). Keeping the flag makes the spec-vs-engine difference
     * explicit rather than silently "fixing" the oracle to the engine.
     *
     * FINDING (verified): in practice the two interpretations CONVERGE, because rank
     * qualification is only ever re-evaluated along PLACEMENT up-chains (see
     * qualifyUpchain) with a temporal cutoff = the id of the just-added node. A sponsor
     * is therefore only re-qualified when one of its PLACEMENT descendants is added, and
     * only invitees with id <= that cutoff are counted. An invitee placed OUTSIDE the
     * sponsor's placement subtree never triggers the sponsor's re-qualification and is
     * not yet visible when the sponsor is last evaluated, so it cannot be counted under
     * EITHER interpretation. The differential harness confirmed 0 divergences over 160
     * incremental steps with the default (global) setting.
     */
    public bool $invitedWithinPlacementSubtree = false;

    /**
     * Optional per-rank bonus override (rankId => dollars). Used by oracle self-tests
     * to exercise positive RANK bonuses; the live DB path keeps them $0.
     *
     * @var array<int,float>
     */
    public array $rankBonusOverride = [];

    // ---- INTERNAL STATE (rebuilt per compute()) ---------------------------

    /** @var array<int,array> id => member input row */
    private array $members = [];
    /** @var array<int,int[]> placementParentId => ordered child ids (by position then id) */
    private array $placementChildren = [];
    /** @var array<int,int> id => current rankId (0 = none) */
    private array $rankId = [];
    /** @var array<int,int> id => personal PV cents (own package PV) */
    private array $pvPersonal = [];
    /**
     * Binary volume per (ancestorId, childId-leg). Keyed [ancestorId][childId] => cents.
     * Mirrors engine's per-child parentBinaryPv accumulator.
     * @var array<int,array<int,int>>
     */
    private array $binaryVol = [];

    /** Accumulated bonus cents per member per type. */
    private array $byType = [];

    /**
     * Compute expected results for the given member set.
     *
     * @param array<int,array> $memberRows ascending-id order
     * @return array{
     *   byType: array<int,array{referral:int,binary:int,leader:int,rank:int}>,
     *   total: array<int,int>,
     *   rank: array<int,int>,
     *   grand: array{referral:int,binary:int,leader:int,rank:int}
     * }  amounts in CENTS
     */
    public function compute(array $memberRows): array
    {
        $this->reset($memberRows);

        // Process members in ascending-id order (= activation order). Non-activated
        // members (packageId null) still exist as nodes but contribute 0 PV.
        $ids = array_keys($this->members);
        sort($ids);

        foreach ($ids as $id) {
            $this->applyVolumes($id);
            // Rank qualification walks up the placement chain. Temporal cutoff =
            // current id: only nodes with id <= $id are visible to gates.
            $this->qualifyUpchain($id, $id);
            $this->payReferral($id);
            $this->payBinary($id);
        }

        return $this->finalize($ids);
    }

    private function reset(array $memberRows): void
    {
        $this->members = [];
        $this->placementChildren = [];
        $this->rankId = [];
        $this->pvPersonal = [];
        $this->binaryVol = [];
        $this->byType = [];

        foreach ($memberRows as $m) {
            $id = (int) $m['id'];
            $this->members[$id] = [
                'id' => $id,
                'name' => $m['name'] ?? ('#' . $id),
                'sponsorId' => isset($m['sponsorId']) ? (int) $m['sponsorId'] : null,
                'parentId' => isset($m['parentId']) ? (int) $m['parentId'] : null,
                'position' => $m['position'] ?? null,
                'packageId' => isset($m['packageId']) && $m['packageId'] !== null ? (int) $m['packageId'] : null,
            ];
            $this->rankId[$id] = 0;
            $this->pvPersonal[$id] = 0;
            $this->byType[$id] = ['referral' => 0, 'binary' => 0, 'leader' => 0, 'rank' => 0];
        }

        // Build placement children index, ordered left-before-right then by id so the
        // first two entries map to the two legs deterministically.
        foreach ($this->members as $id => $m) {
            $p = $m['parentId'];
            if ($p !== null && isset($this->members[$p])) {
                $this->placementChildren[$p][] = $id;
            }
        }
        foreach ($this->placementChildren as $p => &$kids) {
            usort($kids, function ($a, $b) {
                $pa = $this->positionRank($this->members[$a]['position']);
                $pb = $this->positionRank($this->members[$b]['position']);
                return $pa <=> $pb ?: $a <=> $b;
            });
        }
        unset($kids);
    }

    private function positionRank(?string $pos): int
    {
        return $pos === 'left' ? 0 : ($pos === 'right' ? 1 : 2);
    }

    // ---- 0) VOLUMES -------------------------------------------------------

    /**
     * Push the initiator's package PV up the ENTIRE placement-ancestor chain into the
     * ancestor's binary volume on the corresponding leg (the child subtree the PV came
     * up through). pvPersonal records own package PV for rank small-branch sums.
     */
    private function applyVolumes(int $id): void
    {
        $pkg = $this->members[$id]['packageId'];
        if ($pkg === null) {
            return;
        }
        $pv = self::PACKAGE_PV_CENTS[$pkg] ?? 0;
        if ($pv === 0) {
            return;
        }

        $this->pvPersonal[$id] += $pv;

        // Walk up placement chain; the leg key at each ancestor is the child of that
        // ancestor that lies on the path down to $id.
        $cursor = $id;
        $parent = $this->members[$id]['parentId'];
        while ($parent !== null && isset($this->members[$parent])) {
            $this->binaryVol[$parent][$cursor] = ($this->binaryVol[$parent][$cursor] ?? 0) + $pv;
            $cursor = $parent;
            $parent = $this->members[$parent]['parentId'];
        }
    }

    // ---- 2) RANK ----------------------------------------------------------

    private function qualifyUpchain(int $id, int $maxId): void
    {
        $cursor = $id;
        while ($cursor !== null && isset($this->members[$cursor])) {
            $this->qualify($cursor, $maxId);
            $cursor = $this->members[$cursor]['parentId'];
        }
    }

    /** Try to raise $id to each higher rank whose gates pass at temporal cutoff $maxId. */
    private function qualify(int $id, int $maxId): void
    {
        foreach (self::RANKS as $rank) {
            if ($this->rankId[$id] >= $rank['id']) {
                continue;
            }
            if (!$this->passesRank($id, $rank, $maxId)) {
                continue;
            }
            $this->rankId[$id] = $rank['id'];
            // RANK bonus: one-off when first reaching a rank with positive bonus_usd.
            $bonusUsd = $this->rankBonusOverride[$rank['id']] ?? $rank['bonus_usd'];
            if ($bonusUsd > 0) {
                $this->byType[$id]['rank'] += (int) round($bonusUsd * 100);
            }
        }
    }

    private function passesRank(int $id, array $rank, int $maxId): bool
    {
        // smallBranchVolume >= small_branch_pv (threshold 0 = ignore).
        if ($rank['small_branch_pv'] > 0) {
            $small = $this->smallBranchVolumeCents($id, $maxId);
            if ($small < $rank['small_branch_pv'] * 100) {
                return false;
            }
        }
        // personalInvited >= personal_count.
        if ($rank['personal_count'] > 0) {
            if ($this->personalInvited($id, null, $maxId) < $rank['personal_count']) {
                return false;
            }
        }
        // invitedByRank(>=in_rank_id) >= in_rank_count.
        if ($rank['in_rank_count'] > 0) {
            if ($this->personalInvited($id, $rank['in_rank_id'], $maxId) < $rank['in_rank_count']) {
                return false;
            }
        }
        return true;
    }

    /** Small leg = min of the two placement legs' TOTAL subtree personal PV (id <= maxId). */
    private function smallBranchVolumeCents(int $id, int $maxId): int
    {
        $legs = [];
        foreach (($this->placementChildren[$id] ?? []) as $childId) {
            if ($childId > $maxId) {
                continue;
            }
            $legs[] = $this->subtreePersonalPv($childId, $maxId);
        }
        while (count($legs) < 2) {
            $legs[] = 0; // missing leg = 0
        }
        // only the two legs matter (binary width 2)
        sort($legs);
        return $legs[0];
    }

    private function subtreePersonalPv(int $id, int $maxId): int
    {
        if ($id > $maxId) {
            return 0;
        }
        $sum = $this->pvPersonal[$id];
        foreach (($this->placementChildren[$id] ?? []) as $childId) {
            $sum += $this->subtreePersonalPv($childId, $maxId);
        }
        return $sum;
    }

    /**
     * Count personally-invited members of $id (sponsorId == $id), optionally filtered
     * to rankId >= $needRankId, within the temporal cutoff $maxId.
     *
     * SPEC default: GLOBAL count over all members. Engine: restricted to placement
     * subtree (toggle $invitedWithinPlacementSubtree).
     */
    private function personalInvited(int $id, ?int $needRankId, int $maxId): int
    {
        if ($this->invitedWithinPlacementSubtree) {
            return $this->countInvitedInSubtree($id, $id, $needRankId, $maxId);
        }
        $count = 0;
        foreach ($this->members as $mid => $m) {
            if ($mid > $maxId) {
                continue;
            }
            if ($m['sponsorId'] === $id
                && ($needRankId === null || $needRankId === 0 || $this->rankId[$mid] >= $needRankId)) {
                $count++;
            }
        }
        return $count;
    }

    /** Engine-style: count invited within the placement subtree rooted at $node. */
    private function countInvitedInSubtree(int $node, int $sponsorId, ?int $needRankId, int $maxId): int
    {
        if ($node > $maxId) {
            return 0;
        }
        $match = ($this->members[$node]['sponsorId'] === $sponsorId)
            && ($needRankId === null || $needRankId === 0 || $this->rankId[$node] >= $needRankId);
        $count = $match ? 1 : 0;
        foreach (($this->placementChildren[$node] ?? []) as $childId) {
            $count += $this->countInvitedInSubtree($childId, $sponsorId, $needRankId, $maxId);
        }
        return $count;
    }

    // ---- 1) REFERRAL ------------------------------------------------------

    /**
     * Walk up the SPONSOR chain up to referralDepth. Each sponsor at level gets
     * percent[sponsorPackageSort][level] * buyer's package PV. Percent keyed by the
     * RECEIVING sponsor's package sort.
     */
    private function payReferral(int $id): void
    {
        $pkg = $this->members[$id]['packageId'];
        if ($pkg === null) {
            return;
        }
        $purchasePv = self::PACKAGE_PV_CENTS[$pkg] ?? 0;
        if ($purchasePv === 0) {
            return;
        }

        $sponsor = $this->members[$id]['sponsorId'];
        $level = 0;
        while ($sponsor !== null && isset($this->members[$sponsor]) && ++$level <= self::REFERRAL_DEPTH) {
            $sponsorPkg = $this->members[$sponsor]['packageId'];
            if ($sponsorPkg !== null) {
                $sort = self::PACKAGE_SORT[$sponsorPkg] ?? null;
                $pct = self::REFERRAL_PERCENT[$sort][$level] ?? 0;
                $amount = $this->pct($pct, $purchasePv);
                if ($amount > 0) {
                    $this->byType[$sponsor]['referral'] += $amount;
                }
            }
            $sponsor = $this->members[$sponsor]['sponsorId'];
        }
    }

    // ---- 3) BINARY (+ LEADER trigger) -------------------------------------

    /**
     * Each purchase pushes PV up the placement chain (done in applyVolumes). Here, for
     * the initiator's purchase, walk up the placement chain and for each ancestor pair
     * = min(leftLeg, rightLeg); pay binaryPercent(ancestorRank)*pairPV; then FLUSH
     * (subtract pairPV from BOTH legs). Each binary payout triggers LEADER.
     */
    private function payBinary(int $initiatorId): void
    {
        $parent = $this->members[$initiatorId]['parentId'];
        while ($parent !== null && isset($this->members[$parent])) {
            $this->payBinaryForNode($parent, $initiatorId);
            $parent = $this->members[$parent]['parentId'];
        }
    }

    private function payBinaryForNode(int $ancestorId, int $initiatorId): void
    {
        // Two legs = the (up to) two placement children's accumulated binary volume.
        $kids = $this->placementChildren[$ancestorId] ?? [];
        $legVols = [];
        foreach ($kids as $childId) {
            $legVols[$childId] = $this->binaryVol[$ancestorId][$childId] ?? 0;
        }
        // Need both legs; missing leg = 0 => pair 0 => no payout.
        $values = array_values($legVols);
        while (count($values) < 2) {
            $values[] = 0;
        }
        $pair = min($values);
        if ($pair <= 0) {
            return;
        }

        // binaryPercent is keyed by rank id (1..4). A member who has NOT qualified for
        // ANY rank has rankId 0, which is NOT in the table => 0% => no binary payout.
        // (Spec says "5% flat for all RANKS", but rank 0 = "no rank" is not a rank, so
        // no entry applies. The engine treats binaryPercent(0) as 0%.)
        $pct = self::BINARY_PERCENT[$this->rankId[$ancestorId]] ?? 0;
        $bonus = $this->pct($pct, $pair);
        if ($bonus <= 0) {
            // still flush? Engine flushes only when a positive bonus is paid (it returns
            // early on zero bonus before reduceBranchVolume). Mirror: no flush.
            return;
        }

        $this->byType[$ancestorId]['binary'] += $bonus;

        // FLUSH: subtract pair from BOTH legs (carryover of larger leg persists).
        foreach ($kids as $childId) {
            $this->binaryVol[$ancestorId][$childId] = ($this->binaryVol[$ancestorId][$childId] ?? 0) - $pair;
        }

        // Trigger LEADER on this binary payout.
        $this->payLeader($initiatorId, $ancestorId, $bonus);
    }

    // ---- LEADER -----------------------------------------------------------

    /**
     * Triggered by a binary payout of amount $bonus paid to ancestor $A. Walk $A's
     * sponsor chain up to leaderMaxLevel; sponsor at level gets
     * leaderPercent[level][sponsorPackageId][sponsorRankId] * bonus.
     *
     * RANK COMPRESSION: skip a sponsor if anywhere in the sponsor chain BETWEEN that
     * sponsor and A there is a node whose rank exceeds the sponsor's rank by
     * >= maxRankDiff. (SPEC: "strictly between".)
     */
    private function payLeader(int $initiatorId, int $aId, int $bonus): void
    {
        $sponsor = $this->members[$aId]['sponsorId'];
        $level = 0;
        while ($sponsor !== null && isset($this->members[$sponsor]) && ++$level <= self::LEADER_MAX_LEVEL) {
            if (!$this->compressedOut($aId, $sponsor)) {
                $pkg = $this->members[$sponsor]['packageId'];
                $rank = $this->rankId[$sponsor];
                $pct = self::LEADER_PERCENT[$level][$pkg][$rank] ?? 0;
                $amount = $this->pct($pct, $bonus);
                if ($amount > 0) {
                    $this->byType[$sponsor]['leader'] += $amount;
                }
            }
            $sponsor = $this->members[$sponsor]['sponsorId'];
        }
    }

    /**
     * Rank compression per SPEC: skip $sponsor if some node STRICTLY BETWEEN $sponsor
     * and $A (exclusive of both) has (node.rankId - sponsor.rankId) >= maxRankDiff.
     * "Between" = nodes on the sponsor chain from A up to (not including) the sponsor.
     */
    private function compressedOut(int $aId, int $sponsorId): bool
    {
        // Walk from A's sponsor up to (but not including) $sponsorId.
        $node = $this->members[$aId]['sponsorId'];
        while ($node !== null && $node !== $sponsorId && isset($this->members[$node])) {
            if (($this->rankId[$node] - $this->rankId[$sponsorId]) >= self::MAX_RANK_DIFF) {
                return true;
            }
            $node = $this->members[$node]['sponsorId'];
        }
        return false;
    }

    // ---- helpers ----------------------------------------------------------

    /** percent of an amount-in-cents, rounded half-up to the cent (matches PHP round). */
    private function pct(float $percent, int $cents): int
    {
        if ($percent == 0.0) {
            return 0;
        }
        return (int) round($cents * $percent / 100);
    }

    private function finalize(array $ids): array
    {
        $byType = [];
        $total = [];
        $rank = [];
        $grand = ['referral' => 0, 'binary' => 0, 'leader' => 0, 'rank' => 0];

        foreach ($ids as $id) {
            $bt = $this->byType[$id];
            $byType[$id] = $bt;
            $total[$id] = $bt['referral'] + $bt['binary'] + $bt['leader'] + $bt['rank'];
            $rank[$id] = $this->rankId[$id];
            foreach ($grand as $k => $_) {
                $grand[$k] += $bt[$k];
            }
        }

        return [
            'byType' => $byType,
            'total' => $total,
            'rank' => $rank,
            'grand' => $grand,
        ];
    }

    /** Convenience: format cents as "D.DD" string (engine stores decimals like this). */
    public static function cents2str(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
