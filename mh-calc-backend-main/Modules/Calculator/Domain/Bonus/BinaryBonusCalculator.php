<?php

namespace Modules\Calculator\Domain\Bonus;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\Result\CalculationResult;
use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Бинарный бонус: пайринг по объёму малой ветки (PV), процент по рангу получателя,
 * вверх по бинар-родителям. После выплаты спаренный объём списывается с обеих ног
 * (carryover большой ноги сохраняется). Каждая выплата триггерит лидерский бонус.
 *
 * ОТЛИЧИЕ ОТ ИСХОДНИКА (осознанный фикс корректности): малая ветка = min ПО ДВУМ ногам,
 * отсутствующая нога = 0 → бинар платится только при наличии обеих ног. Исходник брал
 * min по присутствующим детям и платил при одной ноге (переплата).
 */
final class BinaryBonusCalculator
{
    private const WIDTH = 2;

    public function __construct(
        private readonly Plan $plan,
        private readonly LeaderBonusCalculator $leader,
    ) {
    }

    public function pay(MemberNode $initiator, CalculationResult $result): void
    {
        $parent = $initiator->parent;
        while ($parent) {
            $this->payForNode($parent, $initiator, $result);
            $parent = $parent->parent;
        }
    }

    private function payForNode(MemberNode $receiver, MemberNode $initiator, CalculationResult $result): void
    {
        $smallLeg = $this->smallBranchVolume($receiver);
        if ($smallLeg->isZero()) {
            return;
        }

        $percent = $this->plan->binaryPercent($receiver->rankId);
        if ($percent->isZero()) {
            return;
        }

        $bonus = $percent->ofPvAsMoney($smallLeg);
        if (!$bonus->isPositive()) {
            return;
        }

        $result->addBonus(new BonusLine(BonusLine::BINARY, $receiver->id, $bonus, sourceId: $initiator->id));
        $this->reduceBranchVolume($receiver, $smallLeg);
        $this->leader->pay($initiator, $receiver, $bonus, $result);
    }

    /** min по двум ногам; отсутствующая нога = 0 (нужны обе ноги для пайринга). */
    private function smallBranchVolume(MemberNode $node): Pv
    {
        $volumes = [];
        foreach ($node->children as $child) {
            $volumes[] = $child->parentBinaryPv;
        }
        while (count($volumes) < self::WIDTH) {
            $volumes[] = Pv::zero();
        }
        return Pv::min(...$volumes);
    }

    private function reduceBranchVolume(MemberNode $node, Pv $volume): void
    {
        foreach ($node->children as $child) {
            $child->parentBinaryPv = $child->parentBinaryPv->subtract($volume);
        }
    }
}
