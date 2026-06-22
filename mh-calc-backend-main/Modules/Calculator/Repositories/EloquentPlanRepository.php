<?php

namespace Modules\Calculator\Repositories;

use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Domain\Repository\PlanRepository;
use Modules\Calculator\Models\PlanSetting;

/**
 * Строит доменный Plan из дефолтов фабрики + оверрайдов из plan_settings.
 * Полный документ плана хранится под ключом `plan` (редактируется из веб-админки,
 * см. PlanSettingsService). Legacy-ключ `rank_bonuses` поддержан как fallback.
 */
class EloquentPlanRepository implements PlanRepository
{
    public function load(): Plan
    {
        return IziGoPlanFactory::fromConfig($this->overridesFromSettings());
    }

    /**
     * Оверрайды плана из plan_settings (документ-конфиг, скаляры). Приоритет — полный
     * документ `plan`; иначе fallback на legacy `rank_bonuses` (rankId => usd).
     *
     * @return array<string,mixed>
     */
    public function overridesFromSettings(): array
    {
        $planDoc = PlanSetting::get('plan');
        if (is_array($planDoc) && $planDoc !== []) {
            return $planDoc;
        }

        $rankBonuses = PlanSetting::get('rank_bonuses');
        if (is_array($rankBonuses) && $rankBonuses !== []) {
            $ranks = IziGoPlanFactory::defaults()['ranks'];
            foreach ($ranks as &$rank) {
                // JSON-ключи приходят строками — сверяем по строковому id.
                $key = (string) $rank['id'];
                if (array_key_exists($key, $rankBonuses)) {
                    $rank['bonus_usd'] = (float) $rankBonuses[$key];
                } elseif (array_key_exists($rank['id'], $rankBonuses)) {
                    $rank['bonus_usd'] = (float) $rankBonuses[$rank['id']];
                }
            }
            unset($rank);

            return ['ranks' => $ranks];
        }

        return [];
    }
}
