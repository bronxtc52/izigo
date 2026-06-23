<?php

namespace Modules\Calculator\Domain\Bonus;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Result\BonusLine;
use Modules\Calculator\Domain\Result\CalculationResult;
use Modules\Calculator\Domain\ValueObject\Money;

/**
 * Лидерский бонус — bonus-on-bonus: процент от начисленного бинарного бонуса,
 * вверх по линии спонсоров (ЛП). Rank-compression: спонсор не получает бонус,
 * если в цепочке есть участник с рангом выше спонсора на maxRankDiff и более.
 */
final class LeaderBonusCalculator
{
    public function __construct(private readonly Plan $plan)
    {
    }

    public function pay(MemberNode $initiator, MemberNode $binaryReceiver, Money $binaryBonus, CalculationResult $result): void
    {
        $sponsor = $binaryReceiver->sponsor;
        $level = 0;
        $maxLevel = $this->plan->leaderMaxLevel();

        while ($sponsor && ++$level <= $maxLevel) {
            $this->payForNode($initiator, $sponsor, $binaryReceiver, $level, $binaryBonus, $result);
            $sponsor = $sponsor->sponsor;
        }
    }

    private function payForNode(MemberNode $initiator, MemberNode $receiver, MemberNode $binaryReceiver, int $level, Money $binaryBonus, CalculationResult $result): void
    {
        if ($this->hasHigherRankInChain($receiver, $binaryReceiver)) {
            return;
        }

        $percent = $this->plan->leaderPercent($level, $receiver->packageId, $receiver->rankId);
        if ($percent->isZero()) {
            return;
        }

        $result->addBonus(new BonusLine(
            BonusLine::LEADER,
            $receiver->id,
            $percent->ofMoney($binaryBonus),
            sourceId: $initiator->id,
            level: $level,
        ));
    }

    /**
     * true, если в цепочке от получателя бинар-бонуса (ВКЛЮЧАЯ его самого) вверх по линии
     * спонсоров до $receiver (спонсора, исключительно) есть узел, чей ранг выше ранга
     * спонсора на maxRankDiff и более — тогда спонсор пропускается (differential/blocking:
     * даунлайн, обогнавший аплайна на ≥2 ранга, перекрывает ему лидерский override).
     * Семантика A-inclusive намеренная — совпадает с легаси BonusLeaderService и ТЗ
     * («разница в статусах между Спонсором и Партнёром»), зафиксирована golden-тестом
     * BonusCalculatorsTest::testLeaderRankCompressionSkipsSponsor.
     * Фикс: проверка null до обращения к ->id (цепочка может дойти до корня).
     */
    private function hasHigherRankInChain(MemberNode $receiver, MemberNode $binaryReceiver): bool
    {
        $chainNode = $binaryReceiver;
        while ($chainNode) {
            if (($chainNode->rankId - $receiver->rankId) >= $this->plan->maxRankDiff) {
                return true;
            }
            $chainNode = $chainNode->sponsor;
            if ($chainNode === null || $chainNode->id === $receiver->id) {
                break;
            }
        }
        return false;
    }
}
