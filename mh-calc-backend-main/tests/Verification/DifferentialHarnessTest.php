<?php

namespace Tests\Verification;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\MemberService;
use Modules\Calculator\Services\WalletService;
use Tests\TestCase;

require_once __DIR__ . '/BonusOracle.php';

/**
 * DIFFERENTIAL HARNESS: runs the LIVE engine (system under test, treated as a black
 * box) and the independent oracle over the SAME incremental binary trees and diffs
 * them per-member, per-type, plus final rank and wallet.
 *
 * Determinism: a fixed set of seeds, each driving a seeded LCG (no rand()/mt_rand),
 * so any divergence reproduces exactly.
 *
 * Flow per step (critical): register member (engine auto-spillover PLACES it) -> read
 * the REAL parent_id/position the engine chose from the DB -> activate (engine recomputes
 * whole tree) -> read the full current topology from the DB -> run the oracle over that
 * real topology -> diff. The oracle CANNOT predict placement, so it must read what the
 * engine actually did.
 *
 * Run: php artisan test tests/Verification/DifferentialHarnessTest.php
 */
class DifferentialHarnessTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDS = [1, 2, 3, 4, 5, 6, 7, 8];
    private const MEMBERS_PER_TREE = 20;

    public function testDifferentialAcrossSeeds(): void
    {
        $memberSvc = app(MemberService::class);
        $activationSvc = app(ActivationService::class);
        $walletSvc = app(WalletService::class);

        $allDivergences = [];
        $stepsRun = 0;

        foreach (self::SEEDS as $seed) {
            // Fresh DB per seed so trees do not interleave.
            $this->refreshDatabaseForSeed();

            $lcg = new Lcg($seed);
            $tgBase = 100000 + $seed * 1000;
            $createdRefs = []; // ref_codes of existing members, for sponsor selection

            for ($i = 0; $i < self::MEMBERS_PER_TREE; $i++) {
                // Pick a sponsor among existing members (root = none for the first).
                $sponsorRef = null;
                if ($createdRefs !== []) {
                    $sponsorRef = $createdRefs[$lcg->nextInt(count($createdRefs))];
                }

                // Register: engine AUTO-spillover places it (we do NOT pass parent/position).
                $member = $memberSvc->registerTelegram(
                    telegramId: $tgBase + $i,
                    name: 'M' . $seed . '_' . $i,
                    username: null,
                    sponsorRef: $sponsorRef,
                );
                $createdRefs[] = $member->ref_code;

                // Read the REAL placement the engine chose (auto-spillover decided it).
                $fresh = Member::query()->find($member->id);
                $this->assertNotNull($fresh, 'member persisted');

                // Deterministic package: 1=Bronze,2=Silver,3=Gold.
                $packageId = $lcg->nextInt(3) + 1;
                $activationSvc->activate($fresh->id, $packageId, "seed{$seed}-act{$i}");
                $stepsRun++;

                // ---- read the FULL current topology from the DB (engine's reality) ----
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

                // ---- oracle over the SAME real topology/order ----
                $oracle = new BonusOracle();
                $expected = $oracle->compute($rows);

                // ---- live engine results (per member, per type) ----
                $divergences = $this->diffStep($seed, $i, $rows, $expected, $walletSvc);
                if ($divergences !== []) {
                    $allDivergences = array_merge($allDivergences, $divergences);
                    // Stop early on first divergent step per seed to keep the dump focused;
                    // remove this break to collect every divergence.
                    break;
                }
            }
        }

        if ($allDivergences !== []) {
            $this->fail(
                "DIFFERENTIAL DIVERGENCES ({$stepsRun} steps run, "
                . count($allDivergences) . " mismatches):\n"
                . implode("\n", $allDivergences)
            );
        }

        $this->assertGreaterThan(0, $stepsRun);
        $this->addToAssertionCount(1);
        fwrite(STDERR, "\n[differential] OK: {$stepsRun} steps across "
            . count(self::SEEDS) . " seeds, no divergences.\n");
    }

    /**
     * Diff one step. Returns a list of human-readable divergence lines (empty = match).
     *
     * @param array<int,array> $rows
     * @param array{byType:array,total:array,rank:array,grand:array} $expected
     * @return string[]
     */
    private function diffStep(int $seed, int $step, array $rows, array $expected, WalletService $walletSvc): array
    {
        $out = [];
        $types = ['referral', 'binary', 'leader', 'rank'];

        foreach ($rows as $row) {
            $id = $row['id'];

            // Live engine per-type from MemberEarning.by_type (decimals like "9.00").
            $earning = MemberEarning::query()->where('member_id', $id)->first();
            $liveByType = [];
            foreach ($types as $t) {
                $liveByType[$t] = $this->decimalToCents($earning->by_type[$t] ?? '0');
            }

            foreach ($types as $t) {
                $exp = $expected['byType'][$id][$t];
                $act = $liveByType[$t];
                if ($exp !== $act) {
                    $out[] = sprintf(
                        'seed%d step%d member#%d %-8s: expected %d c ($%s) != actual %d c ($%s)',
                        $seed, $step, $id, $t,
                        $exp, BonusOracle::cents2str($exp),
                        $act, BonusOracle::cents2str($act),
                    );
                }
            }

            // Final rank.
            $liveRank = (int) (Member::query()->where('id', $id)->value('rank_id') ?? 0);
            $expRank = $expected['rank'][$id];
            if ($liveRank !== $expRank) {
                $out[] = sprintf(
                    'seed%d step%d member#%d rank    : expected %d != actual %d',
                    $seed, $step, $id, $expRank, $liveRank,
                );
            }

            // Wallet available (= cumulative accrual; no holds in this flow) == total earnings.
            $member = Member::query()->find($id);
            $walletCents = $this->decimalToCents($walletSvc->balance($member)['available']);
            $expTotal = $expected['total'][$id];
            if ($walletCents !== $expTotal) {
                $out[] = sprintf(
                    'seed%d step%d member#%d wallet  : expected %d c ($%s) != actual %d c ($%s)',
                    $seed, $step, $id,
                    $expTotal, BonusOracle::cents2str($expTotal),
                    $walletCents, BonusOracle::cents2str($walletCents),
                );
            }
        }

        if ($out !== []) {
            // Context dump: the topology so a divergence is reproducible by hand.
            array_unshift($out, $this->dumpTopology($rows, $expected));
        }

        return $out;
    }

    /** @param array<int,array> $rows */
    private function dumpTopology(array $rows, array $expected): string
    {
        $lines = ['  --- topology at divergent step (id|sponsor|parent|pos|pkg|expRank) ---'];
        foreach ($rows as $r) {
            $lines[] = sprintf(
                '  #%d sp=%s par=%s pos=%-5s pkg=%s expRank=%d',
                $r['id'],
                $r['sponsorId'] ?? '-',
                $r['parentId'] ?? '-',
                $r['position'] ?? '-',
                $r['packageId'] ?? '-',
                $expected['rank'][$r['id']],
            );
        }
        return implode("\n", $lines);
    }

    private function decimalToCents(string|int|float|null $v): int
    {
        if ($v === null) {
            return 0;
        }
        return (int) round(((float) $v) * 100);
    }

    /** RefreshDatabase migrates once; for subsequent seeds, truncate engine tables. */
    private function refreshDatabaseForSeed(): void
    {
        // Wipe all rows from the relevant tables so each seed builds a clean tree.
        foreach ([
            'member_bonus_lines', 'member_earnings', 'ledger_entries', 'member_wallets',
            'activation_events', 'member_roles', 'members',
        ] as $table) {
            try {
                \DB::table($table)->delete();
            } catch (\Throwable $e) {
                // table may not exist in some builds; ignore.
            }
        }
    }
}

/**
 * Tiny deterministic Linear Congruential Generator (Numerical Recipes constants).
 * Deterministic across runs/platforms so divergences reproduce exactly.
 */
final class Lcg
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0x7fffffff) ?: 1;
    }

    /** Next pseudo-random int in [0, $bound). */
    public function nextInt(int $bound): int
    {
        // 32-bit LCG: state = (1664525*state + 1013904223) mod 2^32
        $this->state = (1664525 * $this->state + 1013904223) & 0xffffffff;
        return intdiv($this->state, 1) % $bound;
    }
}
