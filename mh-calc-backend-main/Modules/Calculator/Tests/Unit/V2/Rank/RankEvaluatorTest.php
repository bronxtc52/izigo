<?php

namespace Modules\Calculator\Tests\Unit\V2\Rank;

use Modules\Calculator\V2\Domain\Policy\QualificationVariantRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Domain\Policy\StatusRule;
use Modules\Calculator\V2\Domain\Rank\QualificationInput;
use Modules\Calculator\V2\Domain\Rank\RankCandidate;
use Modules\Calculator\V2\Domain\Rank\RankEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * T05 [ДЕНЬГИ: ранг -> ставки/капы/глубина]: чистое ядро лестницы (CAL-RANK-001).
 * PV-границы малой ветки, компаратор вариантов, только повышение (DEC-020),
 * определение скачка (высший проходной ранг).
 */
class RankEvaluatorTest extends TestCase
{
    private RankEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new RankEvaluator();
    }

    /** @return array<string, StatusRule> компактная лестница (пороги как в DefaultPolicyConfig). */
    private function ladder(): array
    {
        $mk = fn (StatusCode $c, int $ord, ?int $sb, ?int $refs, ?StatusCode $anchor, ?StatusCode $support, array $variants) =>
            new StatusRule($c, $ord, 500, 0, 0, 0, null, null, null, $sb, $refs, $anchor, $support, $variants);

        $v = fn (string $code, int $a, int $s, string $cmp, bool $d) => new QualificationVariantRule($code, $a, $s, $cmp, $d);

        return [
            'CLIENT' => new StatusRule(StatusCode::CLIENT, 0, 0, 0, 0, 0, 100),
            'CONSULTANT' => new StatusRule(StatusCode::CONSULTANT, 1, 500, 0, 0, 0, null, 1, 100),
            'MANAGER' => $mk(StatusCode::MANAGER, 2, 1000, 4, null, null, []),
            'BRONZE_MANAGER' => $mk(StatusCode::BRONZE_MANAGER, 3, 3000, 8, null, null, []),
            'SILVER_MANAGER' => $mk(StatusCode::SILVER_MANAGER, 4, 8000, null, StatusCode::MANAGER, null, [
                $v('V1', 3, 0, 'at_least', false),
            ]),
            'GOLD_MANAGER' => $mk(StatusCode::GOLD_MANAGER, 5, 20000, null, StatusCode::SILVER_MANAGER, StatusCode::BRONZE_MANAGER, [
                $v('V1', 2, 0, 'at_least', false),
            ]),
            'DIRECTOR' => $mk(StatusCode::DIRECTOR, 7, 150000, null, StatusCode::PLATINUM_MANAGER, StatusCode::GOLD_MANAGER, [
                $v('V1', 2, 0, 'at_least', false),
            ]),
        ];
    }

    private function cand(int $id, StatusCode $rank, bool $l1, int $root): RankCandidate
    {
        return new RankCandidate($id, $rank->value, $rank->ordinal(), $l1, $root);
    }

    public function testManagerRequiresBranchPvAndReferrals(): void
    {
        // sb 1000 + 4 рефа => MANAGER; BRONZE (sb3000/8) не проходит => achieved MANAGER.
        $input = new QualificationInput(1, 1, '1000', 4, []);
        $result = $this->evaluator->evaluate($this->ladder(), $input);
        $this->assertTrue($result->passed);
        $this->assertSame(StatusCode::MANAGER, $result->achievedRank->code);
    }

    public function testManagerFailsBelowBranchPvThreshold(): void
    {
        $input = new QualificationInput(1, 1, '999.999999', 4, []);
        $result = $this->evaluator->evaluate($this->ladder(), $input);
        $this->assertFalse($result->passed);
    }

    public function testDirectorPvBoundary(): void
    {
        $candidates = [
            $this->cand(10, StatusCode::PLATINUM_MANAGER, true, 10),
            $this->cand(20, StatusCode::PLATINUM_MANAGER, true, 20),
        ];
        // 149999.99 — PV-гейт Директора не пройден.
        $below = new QualificationInput(1, 6, '149999.990000', 0, $candidates);
        $this->assertFalse($this->evaluator->evaluate($this->ladder(), $below)->passed);

        // 150000 — гейт пройден, 2 Platinum на L1 закрывают вариант => DIRECTOR.
        $ok = new QualificationInput(1, 6, '150000', 0, $candidates);
        $result = $this->evaluator->evaluate($this->ladder(), $ok);
        $this->assertTrue($result->passed);
        $this->assertSame(StatusCode::DIRECTOR, $result->achievedRank->code);
    }

    public function testExactComparatorRejectsHigherRankAtVariant(): void
    {
        $ladder = $this->ladder();
        // Заменяем вариант Директора на exact.
        $ladder['DIRECTOR'] = new StatusRule(
            StatusCode::DIRECTOR, 7, 700, 0, 0, 0, null, null, null, 150000, null,
            StatusCode::PLATINUM_MANAGER, StatusCode::GOLD_MANAGER,
            [new QualificationVariantRule('V1', 2, 0, 'exact', false)],
        );
        // 2 Director на L1: at_least прошёл бы, exact — нет (нужны именно Platinum).
        $candidates = [
            $this->cand(10, StatusCode::DIRECTOR, true, 10),
            $this->cand(20, StatusCode::DIRECTOR, true, 20),
        ];
        $input = new QualificationInput(1, 6, '150000', 0, $candidates);
        $this->assertFalse($this->evaluator->evaluate($ladder, $input)->passed);
    }

    public function testMonotonicNeverReturnsBelowCurrentRank(): void
    {
        // Текущий GOLD (ord 5); сеть тянет только на MANAGER — апгрейда нет.
        $input = new QualificationInput(1, 5, '1000', 4, []);
        $result = $this->evaluator->evaluate($this->ladder(), $input);
        $this->assertFalse($result->passed);
        $this->assertNull($result->achievedRank);
    }

    public function testJumpReturnsHighestPassingRank(): void
    {
        // Consultant (ord1), sb 3000 + 8 рефералов => проходит и MANAGER, и BRONZE;
        // высший проходной = BRONZE_MANAGER (сервис затем впишет и MANAGER, DEC-040).
        $input = new QualificationInput(1, 1, '3000', 8, []);
        $result = $this->evaluator->evaluate($this->ladder(), $input);
        $this->assertTrue($result->passed);
        $this->assertSame(StatusCode::BRONZE_MANAGER, $result->achievedRank->code);
    }
}
