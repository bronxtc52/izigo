<?php

namespace Modules\Calculator\Domain\Bonus;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\Result\CalculationResult;

/**
 * Реферальный бонус: процент от PV купленного пакета инициатора, вверх по линии
 * спонсоров (ЛП) до plan.referralDepth. Процент зависит от пакета получателя и уровня.
 */
final class ReferralBonusCalculator
{
    public function __construct(private readonly Plan $plan)
    {
    }

    public function pay(MemberNode $initiator, CalculationResult $result): void
    {
        $package = $this->plan->package($initiator->packageId);
        if ($package === null || $package->pv->isZero()) {
            return;
        }
        $purchasePv = $package->pv;

        $sponsor = $initiator->sponsor;
        $level = 0;
        while ($sponsor && ++$level <= $this->plan->referralDepth) {
            $receiverPackage = $this->plan->package($sponsor->packageId);
            if ($receiverPackage !== null) {
                $percent = $this->plan->referralPercent($receiverPackage->sort, $level);
                $bonus = $percent->ofPvAsMoney($purchasePv);
                if ($bonus->isPositive()) {
                    $result->addBonus(new BonusLine(
                        BonusLine::REFERRAL,
                        $sponsor->id,
                        $bonus,
                        sourceId: $initiator->id,
                        level: $level,
                    ));
                }
            }
            $sponsor = $sponsor->sponsor;
        }
    }
}
