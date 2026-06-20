<?php

namespace Modules\Calculator\Tests\Unit\Domain;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Rank\RankSnapshot;
use Modules\Calculator\Domain\ValueObject\Pv;
use PHPUnit\Framework\TestCase;

/**
 * Темпоральная отсечка по maxNodeId и правило «обе ноги» для малой ветки.
 */
class RankSnapshotTest extends TestCase
{
    public function testTemporalCutoffExcludesLaterNodes(): void
    {
        $x = new MemberNode(1, 'x', 0, 3);
        $a = new MemberNode(2, 'a', 1, 3);
        $b = new MemberNode(5, 'b', 1, 3); // высокий id — «появился позже»
        $a->parent = $x;
        $b->parent = $x;
        $x->children = [$a, $b];
        $a->pvPersonal = Pv::fromUnits(540);
        $b->pvPersonal = Pv::fromUnits(540);

        // maxNodeId=4 → b(id5) исключён → правая нога = 0 → малая ветка = 0
        $cut = new RankSnapshot($x, 4);
        $this->assertSame(0, $cut->smallBranchVolume->hundredths);

        // maxNodeId=5 → обе ноги по 540 → малая ветка = 540 PV
        $full = new RankSnapshot($x, 5);
        $this->assertSame(54000, $full->smallBranchVolume->hundredths);
    }

    public function testSmallBranchRequiresBothLegs(): void
    {
        // одна нога с объёмом, второй нет → малая ветка = 0
        $x = new MemberNode(1, 'x', 0, 3);
        $a = new MemberNode(2, 'a', 1, 3);
        $a->parent = $x;
        $x->children = [$a];
        $a->pvPersonal = Pv::fromUnits(5000);

        $snapshot = new RankSnapshot($x, 2);
        $this->assertSame(0, $snapshot->smallBranchVolume->hundredths);
    }
}
