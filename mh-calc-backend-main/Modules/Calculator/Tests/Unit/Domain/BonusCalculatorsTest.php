<?php

namespace Modules\Calculator\Tests\Unit\Domain;

use Modules\Calculator\Domain\Bonus\BinaryBonusCalculator;
use Modules\Calculator\Domain\Bonus\LeaderBonusCalculator;
use Modules\Calculator\Domain\Bonus\ReferralBonusCalculator;
use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\Result\CalculationResult;
use Modules\Calculator\Domain\ValueObject\Money;
use Modules\Calculator\Domain\ValueObject\Pv;
use PHPUnit\Framework\TestCase;

/**
 * Golden unit-тесты калькуляторов (чистые, без БД/Laravel). Значения посчитаны вручную.
 * Модель PV: бонусы = % от PV, 1 PV = $1 (Money в центах).
 */
class BonusCalculatorsTest extends TestCase
{
    private function node(int $id, int $sponsorId, ?int $packageId): MemberNode
    {
        return new MemberNode($id, "n$id", $sponsorId, $packageId);
    }

    public function testBinaryPairingAndCarryover(): void
    {
        $plan = IziGoPlanFactory::create();
        $binary = new BinaryBonusCalculator($plan, new LeaderBonusCalculator($plan));

        $r = $this->node(1, 0, 1);
        $r->rankId = 1;
        $l = $this->node(2, 1, 3);
        $rt = $this->node(3, 1, 3);
        $l->parent = $r;
        $rt->parent = $r;
        $r->children = [$l, $rt];
        $l->parentBinaryPv = Pv::fromUnits(100);
        $rt->parentBinaryPv = Pv::fromUnits(40);

        $result = new CalculationResult();
        $binary->pay($l, $result); // получатель = l.parent = r

        // малая нога = min(100,40)=40 PV; 5% = 2 PV = $2.00
        $this->assertSame(200, $result->totalByType(BonusLine::BINARY)->cents);
        $this->assertSame(200, $result->totalForMember(1)->cents);
        // carryover: обе ноги уменьшены на 40 PV
        $this->assertSame(6000, $l->parentBinaryPv->hundredths);  // 60 PV
        $this->assertSame(0, $rt->parentBinaryPv->hundredths);
    }

    public function testBinaryRequiresBothLegs(): void
    {
        // Фикс корректности: при одной ноге бинар не платится (исходник платил — баг).
        $plan = IziGoPlanFactory::create();
        $binary = new BinaryBonusCalculator($plan, new LeaderBonusCalculator($plan));

        $r = $this->node(1, 0, 1);
        $r->rankId = 1;
        $l = $this->node(2, 1, 3);
        $l->parent = $r;
        $r->children = [$l];
        $l->parentBinaryPv = Pv::fromUnits(100);

        $result = new CalculationResult();
        $binary->pay($l, $result);

        $this->assertSame(0, $result->totalByType(BonusLine::BINARY)->cents);
    }

    public function testReferralByLevelAndPackage(): void
    {
        $plan = IziGoPlanFactory::create();
        $referral = new ReferralBonusCalculator($plan);

        $init = $this->node(3, 2, 1); // Bronze 90 PV
        $s1 = $this->node(2, 1, 2);   // Silver (sort 2)
        $s2 = $this->node(1, 0, 3);   // Gold (sort 3)
        $init->sponsor = $s1;
        $s1->sponsor = $s2;

        $result = new CalculationResult();
        $referral->pay($init, $result);

        // L1 s1 (sort2): 10% от 90 PV = 9 PV = $9.00
        $this->assertSame(900, $result->totalForMember(2)->cents);
        // L2 s2 (sort3): 8% от 90 PV = 7.2 PV = $7.20
        $this->assertSame(720, $result->totalForMember(1)->cents);
        $this->assertSame(1620, $result->totalByType(BonusLine::REFERRAL)->cents);
    }

    public function testLeaderBonusOnBonusTwoLevels(): void
    {
        $plan = IziGoPlanFactory::create();
        $leader = new LeaderBonusCalculator($plan);

        $r = $this->node(3, 2, 3);
        $r->rankId = 2;
        $s1 = $this->node(2, 1, 3); // Gold, rank 2
        $s1->rankId = 2;
        $s2 = $this->node(1, 0, 3); // Gold, rank 4
        $s2->rankId = 4;
        $r->sponsor = $s1;
        $s1->sponsor = $s2;

        $result = new CalculationResult();
        $leader->pay($r, $r, Money::fromDollars(100), $result);

        // L1 s1: leader[1][Gold][rank2]=20% от $100 = $20
        $this->assertSame(2000, $result->totalForMember(2)->cents);
        // L2 s2: leader[2][Gold][rank4]=10% от $100 = $10
        $this->assertSame(1000, $result->totalForMember(1)->cents);
        $this->assertSame(3000, $result->totalByType(BonusLine::LEADER)->cents);
    }

    public function testLeaderRankCompressionSkipsSponsor(): void
    {
        $plan = IziGoPlanFactory::create();
        $leader = new LeaderBonusCalculator($plan);

        $r = $this->node(3, 2, 3);
        $r->rankId = 4;             // высокий ранг в цепочке
        $s1 = $this->node(2, 1, 3);
        $s1->rankId = 1;            // спонсор ниже на 3 статуса -> пропуск (MAX_RANK_DIFF=2)
        $s2 = $this->node(1, 0, 3);
        $s2->rankId = 4;
        $r->sponsor = $s1;
        $s1->sponsor = $s2;

        $result = new CalculationResult();
        $leader->pay($r, $r, Money::fromDollars(100), $result);

        $this->assertSame(0, $result->totalForMember(2)->cents, 's1 пропущен компрессией');
        $this->assertSame(1000, $result->totalForMember(1)->cents, 's2 (Gold rank4) получает L2 10%');
    }

    public function testLeaderChainToRootDoesNotCrash(): void
    {
        // Защита от null-deref: цепочка спонсоров доходит до корня (sponsor=null).
        $plan = IziGoPlanFactory::create();
        $leader = new LeaderBonusCalculator($plan);

        $r = $this->node(2, 1, 3);
        $r->rankId = 2;
        $root = $this->node(1, 0, 3);
        $root->rankId = 2;
        $r->sponsor = $root;
        $root->sponsor = null;

        $result = new CalculationResult();
        $leader->pay($r, $r, Money::fromDollars(100), $result);

        // L1 root: leader[1][Gold][rank2]=20% = $20; null-цепочка не роняет расчёт
        $this->assertSame(2000, $result->totalForMember(1)->cents);
    }
}
