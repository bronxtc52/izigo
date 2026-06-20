<?php

namespace Modules\Calculator\Tests\Unit\Domain;

use Modules\Calculator\Domain\CompensationEngine;
use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Model\Network;
use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end golden-тесты движка (детерминированно, без БД). Прогон всей структуры
 * в порядке постановки. Значения посчитаны вручную (PV-модель, 1 PV = $1).
 */
class CompensationEngineTest extends TestCase
{
    private function engine(): CompensationEngine
    {
        return new CompensationEngine(IziGoPlanFactory::create());
    }

    public function testSmallStructureReferralAndBinary(): void
    {
        // R(Bronze, корень) с двумя ногами: L(Gold) и Rt(Bronze), оба приглашены R.
        $net = new Network();
        $net->add(new MemberNode(1, 'R', 0, 1));
        $net->add(new MemberNode(2, 'L', 1, 3));
        $net->add(new MemberNode(3, 'Rt', 1, 1));
        $net->link([1 => null, 2 => 1, 3 => 1]);

        $result = $this->engine()->calculate($net);

        // Реферал R: от L (10% от 540 = $54) + от Rt (10% от 90 = $9) = $63.00
        $this->assertSame(6300, $result->totalByType(BonusLine::REFERRAL)->cents);
        // Бинар R: малая нога min(540,90)=90 PV * 5% = $4.50
        $this->assertSame(450, $result->totalByType(BonusLine::BINARY)->cents);
        $this->assertSame(0, $result->totalByType(BonusLine::LEADER)->cents);
        $this->assertSame(6750, $result->grandTotal()->cents);
        $this->assertSame(6750, $result->totalForMember(1)->cents);

        // carryover: большая нога L сохранила 540-90=450 PV, Rt обнулилась
        $this->assertSame(45000, $net->get(2)->parentBinaryPv->hundredths);
        $this->assertSame(0, $net->get(3)->parentBinaryPv->hundredths);
    }

    public function testRankQualificationToManager(): void
    {
        // X с двумя ногами по 2 Gold (1080 PV каждая) и 4 лично приглашёнными -> ранг 2 (manager).
        $net = new Network();
        $net->add(new MemberNode(1, 'X', 0, 3));
        $net->add(new MemberNode(2, 'A', 1, 3));
        $net->add(new MemberNode(3, 'B', 1, 3));
        $net->add(new MemberNode(4, 'C', 1, 3));
        $net->add(new MemberNode(5, 'D', 1, 3));
        $net->link([1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 3]);

        $this->engine()->calculate($net);

        // малая ветка = min(A+C, B+D) = min(1080,1080)=1080 >= 1000; лично приглашённых 4 >= 4
        $this->assertSame(2, $net->get(1)->rankId, 'X должен достичь ранга manager (2)');
        // нижние узлы без своих приглашённых остаются ранг 0
        $this->assertSame(0, $net->get(4)->rankId);
    }

    public function testMultiStepBinaryCarryover(): void
    {
        // R: левая нога A(Gold 540), правая B(Bronze 90) + C(Bronze 90 под B).
        // Две выплаты бинара по 90 PV*5%=$4.50; большая нога A копит остаток.
        $net = new Network();
        $net->add(new MemberNode(1, 'R', 0, 1));
        $net->add(new MemberNode(2, 'A', 1, 3));
        $net->add(new MemberNode(3, 'B', 1, 1));
        $net->add(new MemberNode(4, 'C', 1, 1));
        $net->link([1 => null, 2 => 1, 3 => 1, 4 => 3]);

        $result = $this->engine()->calculate($net);

        // два пайринга по min(*,90)=90 PV * 5% = $4.50 каждый = $9.00
        $this->assertSame(900, $result->totalByType(BonusLine::BINARY)->cents);
        // carryover большой ноги A: 540 - 90 - 90 = 360 PV
        $this->assertSame(36000, $net->get(2)->parentBinaryPv->hundredths);
        $this->assertSame(0, $net->get(3)->parentBinaryPv->hundredths);
    }

    public function testRankBonusOnAchievement(): void
    {
        // Ранговый бонус $85 за manager (ранг 2). Структура из теста квалификации.
        $engine = new CompensationEngine(IziGoPlanFactory::create([2 => Money::fromDollars(85)]));
        $net = new Network();
        $net->add(new MemberNode(1, 'X', 0, 3));
        $net->add(new MemberNode(2, 'A', 1, 3));
        $net->add(new MemberNode(3, 'B', 1, 3));
        $net->add(new MemberNode(4, 'C', 1, 3));
        $net->add(new MemberNode(5, 'D', 1, 3));
        $net->link([1 => null, 2 => 1, 3 => 1, 4 => 2, 5 => 3]);

        $result = $engine->calculate($net);

        // только X достигает ранга 2 (бонус $85); ранг 1 имеет бонус $0
        $this->assertSame(8500, $result->totalByType(BonusLine::RANK)->cents);
    }
}
