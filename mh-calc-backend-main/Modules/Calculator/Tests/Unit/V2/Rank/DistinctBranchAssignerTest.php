<?php

namespace Modules\Calculator\Tests\Unit\V2\Rank;

use Modules\Calculator\V2\Domain\Policy\QualificationVariantRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Domain\Policy\StatusRule;
use Modules\Calculator\V2\Domain\Rank\DistinctBranchAssigner;
use Modules\Calculator\V2\Domain\Rank\RankAssignment;
use Modules\Calculator\V2\Domain\Rank\RankCandidate;
use PHPUnit\Framework\TestCase;

/**
 * T05 [ДЕНЬГИ: ранг определяет ставки 5-9%, капы, глубину лидерского]:
 * детерминированное назначение кандидатов на слоты вариантов квалификации
 * (BR-RANK-001 + DEC-022..024). Ядро распределения — без БД.
 */
class DistinctBranchAssignerTest extends TestCase
{
    private DistinctBranchAssigner $assigner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assigner = new DistinctBranchAssigner();
    }

    /** DIRECTOR: anchor Platinum, support Gold. */
    private function director(): StatusRule
    {
        return new StatusRule(
            code: StatusCode::DIRECTOR,
            ordinal: 7,
            binaryRateBp: 700,
            monthlyCapCents: 0,
            halfMonthCapCents: 0,
            eliteLeadershipDepth: 5,
            smallBranchPvMin: 150000,
            anchorRank: StatusCode::PLATINUM_MANAGER,
            supportRank: StatusCode::GOLD_MANAGER,
        );
    }

    private function variant(string $code, int $anchor, int $support, string $cmp, bool $distinct): QualificationVariantRule
    {
        return new QualificationVariantRule($code, $anchor, $support, $cmp, $distinct);
    }

    private function cand(int $id, StatusCode $rank, bool $l1, int $root): RankCandidate
    {
        return new RankCandidate($id, $rank->value, $rank->ordinal(), $l1, $root);
    }

    public function testVariant1TwoAnchorsOnL1DistinctNodesPass(): void
    {
        $v1 = $this->variant('V1', 2, 0, QualificationVariantRule::COMPARATOR_AT_LEAST, false);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(20, StatusCode::PLATINUM_MANAGER, true, 20),
        ];
        $assignment = $this->assigner->assign($this->director(), $v1, $candidates);
        $this->assertNotNull($assignment);
        $this->assertCount(2, $assignment->slots);
        $this->assertSame(RankAssignment::SLOT_ANCHOR, $assignment->slots[0]['slot']);
    }

    public function testVariant1AtLeastCountsHigherRank(): void
    {
        // 1 Platinum + 1 Director на L1; at_least: Director >= Platinum => 2 anchors, pass.
        $v1 = $this->variant('V1', 2, 0, QualificationVariantRule::COMPARATOR_AT_LEAST, false);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(20, StatusCode::DIRECTOR, true, 20),
        ];
        $this->assertNotNull($this->assigner->assign($this->director(), $v1, $candidates));
    }

    public function testVariant1ExactRejectsHigherRank(): void
    {
        // exact: Director != Platinum => только 1 Platinum, fail.
        $v1 = $this->variant('V1', 2, 0, QualificationVariantRule::COMPARATOR_EXACT, false);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(20, StatusCode::DIRECTOR, true, 20),
        ];
        $this->assertNull($this->assigner->assign($this->director(), $v1, $candidates));
    }

    public function testVariant2FiveDistinctRootBranchesPass(): void
    {
        // 1 Platinum anchor L1 + 4 Gold в 4 разных ветвях = 5 различных корневых ветвей.
        $v2 = $this->variant('V2', 1, 4, QualificationVariantRule::COMPARATOR_AT_LEAST, true);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(21, StatusCode::GOLD_MANAGER, false, 20),
            $this->cand(31, StatusCode::GOLD_MANAGER, false, 30),
            $this->cand(41, StatusCode::GOLD_MANAGER, false, 40),
            $this->cand(51, StatusCode::GOLD_MANAGER, false, 50),
        ];
        $assignment = $this->assigner->assign($this->director(), $v2, $candidates);
        $this->assertNotNull($assignment);
        $this->assertCount(5, $assignment->slots);
        // Все слоты — из попарно РАЗНЫХ корневых ветвей.
        $roots = array_column($assignment->slots, 'root_branch_member_id');
        $this->assertCount(5, array_unique($roots));
    }

    public function testDirectorS38TwoGoldSameRootBranchGiveOneSlot(): void
    {
        // Пример Директора PPTX:S38 — два Gold в ОДНОЙ корневой ветви (root 20) дают
        // один слот. Anchor(10) + различные support-ветви {20,30,40} = 4 ветви < 5 => fail.
        $v2 = $this->variant('V2', 1, 4, QualificationVariantRule::COMPARATOR_AT_LEAST, true);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(21, StatusCode::GOLD_MANAGER, false, 20),
            $this->cand(22, StatusCode::GOLD_MANAGER, false, 20), // та же ветвь 20
            $this->cand(31, StatusCode::GOLD_MANAGER, false, 30),
            $this->cand(41, StatusCode::GOLD_MANAGER, false, 40),
        ];
        $this->assertNull($this->assigner->assign($this->director(), $v2, $candidates));

        // Появился Gold в НОВОЙ ветви (50) — теперь 5 различных ветвей => pass.
        $candidates[] = $this->cand(51, StatusCode::GOLD_MANAGER, false, 50);
        $this->assertNotNull($this->assigner->assign($this->director(), $v2, $candidates));
    }

    public function testCandidateNotUsedTwiceAnchorDoesNotFillSupport(): void
    {
        // Единственный Platinum L1 (ветвь 10) + только 3 Gold-ветви: anchor нельзя
        // переиспользовать под support => всего 4 ветви < 5 => fail.
        $v2 = $this->variant('V2', 1, 4, QualificationVariantRule::COMPARATOR_AT_LEAST, true);
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(31, StatusCode::GOLD_MANAGER, false, 30),
            $this->cand(41, StatusCode::GOLD_MANAGER, false, 40),
            $this->cand(51, StatusCode::GOLD_MANAGER, false, 50),
        ];
        $this->assertNull($this->assigner->assign($this->director(), $v2, $candidates));
    }

    public function testVariant3EightDistinctBranchesPass(): void
    {
        $v3 = $this->variant('V3', 0, 8, QualificationVariantRule::COMPARATOR_AT_LEAST, true);
        $candidates = [];
        for ($i = 1; $i <= 8; $i++) {
            $candidates[] = $this->cand(100 + $i, StatusCode::GOLD_MANAGER, false, 100 + $i);
        }
        $assignment = $this->assigner->assign($this->director(), $v3, $candidates);
        $this->assertNotNull($assignment);
        $this->assertCount(8, $assignment->slots);

        // Только 7 ветвей => fail.
        array_pop($candidates);
        $this->assertNull($this->assigner->assign($this->director(), $v3, $candidates));
    }

    public function testDeterministicUnderInputPermutation(): void
    {
        $v3 = $this->variant('V3', 0, 8, QualificationVariantRule::COMPARATOR_AT_LEAST, true);
        $candidates = [];
        for ($i = 1; $i <= 10; $i++) {
            $candidates[] = $this->cand(100 + $i, StatusCode::GOLD_MANAGER, false, 100 + $i);
        }

        $a = $this->assigner->assign($this->director(), $v3, $candidates);
        $shuffled = array_reverse($candidates);
        $b = $this->assigner->assign($this->director(), $v3, $shuffled);

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame(
            array_column($a->slots, 'qualifier_partner_id'),
            array_column($b->slots, 'qualifier_partner_id'),
        );
    }
}
