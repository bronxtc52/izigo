<?php

namespace Modules\Calculator\Domain;

use Modules\Calculator\Domain\Bonus\BinaryBonusCalculator;
use Modules\Calculator\Domain\Bonus\LeaderBonusCalculator;
use Modules\Calculator\Domain\Bonus\ReferralBonusCalculator;
use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Model\Network;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Rank\RankQualifier;
use Modules\Calculator\Domain\Result\CalculationResult;

/**
 * Детерминированный движок начислений (без БД/побочек). Прогоняет сеть в порядке
 * постановки (по id): объёмы → квалификация рангов → бонусы (реферальный, затем
 * бинарный, который триггерит лидерский). Возвращает CalculationResult.
 *
 * Это симуляция всего жизненного цикла структуры. Для реал-тайм начисления
 * платформы тот же набор калькуляторов вызывается на единичное событие.
 */
final class CompensationEngine
{
    private readonly ReferralBonusCalculator $referral;
    private readonly BinaryBonusCalculator $binary;
    private readonly RankQualifier $rankQualifier;

    public function __construct(private readonly Plan $plan)
    {
        $this->referral = new ReferralBonusCalculator($plan);
        $this->binary = new BinaryBonusCalculator($plan, new LeaderBonusCalculator($plan));
        $this->rankQualifier = new RankQualifier($plan);
    }

    public function calculate(Network $network): CalculationResult
    {
        $result = new CalculationResult();

        foreach ($network->orderedById() as $node) {
            $this->applyVolumes($node);
            $this->rankQualifier->qualifyUpchain($node, $node->id, $result);
            $this->referral->pay($node, $result);
            $this->binary->pay($node, $result);
        }

        return $result;
    }

    /** Накопление PV вверх по бинару: каждому предку parentBinaryPv += pv; личный/групповой объём. */
    private function applyVolumes(MemberNode $initiator): void
    {
        $package = $this->plan->package($initiator->packageId);
        if ($package === null || $package->pv->isZero()) {
            return;
        }
        $pv = $package->pv;

        $cursor = $initiator;
        while ($cursor) {
            $cursor->parentBinaryPv = $cursor->parentBinaryPv->add($pv);
            if ($cursor === $initiator) {
                $cursor->pvPersonal = $cursor->pvPersonal->add($pv);
            } else {
                $cursor->pvGroup = $cursor->pvGroup->add($pv);
            }
            $cursor = $cursor->parent;
        }
    }
}
