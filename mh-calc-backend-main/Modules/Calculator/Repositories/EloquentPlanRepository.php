<?php

namespace Modules\Calculator\Repositories;

use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Repository\PlanRepository;
use Modules\Calculator\Domain\ValueObject\Money;
use Modules\Calculator\Models\PlanSetting;

/**
 * Строит доменный Plan из дефолтов фабрики + оверрайдов из plan_settings.
 * В S1 поддержан оверрайд ранг-бонусов (rank_bonuses: {rankId: usd}); полное
 * редактирование процентов/порогов из админки — S3.
 */
class EloquentPlanRepository implements PlanRepository
{
    public function load(): Plan
    {
        $rankBonuses = [];
        $raw = PlanSetting::get('rank_bonuses');
        if (is_array($raw)) {
            foreach ($raw as $rankId => $usd) {
                $rankBonuses[(int) $rankId] = Money::fromDollars((float) $usd);
            }
        }

        return IziGoPlanFactory::create($rankBonuses);
    }
}
