<?php

namespace Modules\Calculator\Tests\Unit\V2\Rank;

use Modules\Calculator\V2\Domain\Rank\RootBranchResolver;
use PHPUnit\Framework\TestCase;

/**
 * T05 [ДЕНЬГИ: ранг определяет ставки/капы]: корневая реферальная ветвь
 * (BR-TREE-001). Первый узел после получателя на пути sponsor_id; два кандидата
 * одной ветви => один root; кандидат вне поддерева => null.
 */
class RootBranchResolverTest extends TestCase
{
    private RootBranchResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new RootBranchResolver();
    }

    public function testCandidateAtDepthResolvesToL1Node(): void
    {
        // P(1) -> A(2) -> B(3) -> C(4); для P корневая ветвь C и B и A = узел A(2).
        $sponsors = [1 => null, 2 => 1, 3 => 2, 4 => 3];
        $this->assertSame(2, $this->resolver->rootBranchFor(1, 4, $sponsors));
        $this->assertSame(2, $this->resolver->rootBranchFor(1, 3, $sponsors));
        $this->assertSame(2, $this->resolver->rootBranchFor(1, 2, $sponsors));
    }

    public function testTwoCandidatesSameBranchShareOneRoot(): void
    {
        // P(1) -> A(2) -> {B(3), C(4)}; B и C — одна корневая ветвь A(2).
        $sponsors = [1 => null, 2 => 1, 3 => 2, 4 => 2];
        $rootB = $this->resolver->rootBranchFor(1, 3, $sponsors);
        $rootC = $this->resolver->rootBranchFor(1, 4, $sponsors);
        $this->assertSame(2, $rootB);
        $this->assertSame($rootB, $rootC);
    }

    public function testCandidateOutsideSubtreeReturnsNull(): void
    {
        // X(9) не в поддереве P(1).
        $sponsors = [1 => null, 2 => 1, 9 => 8, 8 => null];
        $this->assertNull($this->resolver->rootBranchFor(1, 9, $sponsors));
    }

    public function testReceiverItselfIsNotACandidate(): void
    {
        $sponsors = [1 => null, 2 => 1];
        $this->assertNull($this->resolver->rootBranchFor(1, 1, $sponsors));
    }

    public function testTwoDistinctL1BranchesResolveSeparately(): void
    {
        // P(1) -> {A(2), D(5)}; A-ветвь и D-ветвь различны.
        $sponsors = [1 => null, 2 => 1, 3 => 2, 5 => 1, 6 => 5];
        $this->assertSame(2, $this->resolver->rootBranchFor(1, 3, $sponsors));
        $this->assertSame(5, $this->resolver->rootBranchFor(1, 6, $sponsors));
    }
}
