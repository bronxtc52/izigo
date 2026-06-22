<?php

namespace Modules\Calculator\Domain\Plan;

use Modules\Calculator\Domain\ValueObject\Money;
use Modules\Calculator\Domain\ValueObject\Percent;
use Modules\Calculator\Domain\ValueObject\Pv;

/**
 * Дефолтный маркетинг-план IziGo (PV-модель) + сборка доменного {@see Plan} из
 * дефолтов с оверрайдами из админки (plan_settings, см. EloquentPlanRepository).
 *
 * Источник правды параметров — массив {@see defaults()} (скаляры, редактируемый документ).
 * {@see fromConfig()} мёржит оверрайды поверх дефолтов и строит value-objects.
 */
final class IziGoPlanFactory
{
    /**
     * Канонические дефолты плана как редактируемый документ (скаляры, без VO).
     * ВНИМАНИЕ: значения должны совпадать с историческим хардкодом — на этом держится
     * golden-регресс комп-движка (дефолтный расчёт не меняется).
     *
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            // id, sort, name, pv (единицы PV; 90 PV)
            'packages' => [
                ['id' => 1, 'sort' => 1, 'name' => 'Bronze', 'pv' => 90],
                ['id' => 2, 'sort' => 2, 'name' => 'Silver', 'pv' => 180],
                ['id' => 3, 'sort' => 3, 'name' => 'Gold',   'pv' => 540],
            ],
            // id, sort, alias, small_branch_pv, personal_count, in_rank_count, in_rank_id, bonus_usd
            'ranks' => [
                ['id' => 1, 'sort' => 1, 'alias' => 'consultant',     'small_branch_pv' => 0,    'personal_count' => 1, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
                ['id' => 2, 'sort' => 2, 'alias' => 'manager',        'small_branch_pv' => 1000, 'personal_count' => 4, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
                ['id' => 3, 'sort' => 3, 'alias' => 'manager_bronze', 'small_branch_pv' => 3000, 'personal_count' => 8, 'in_rank_count' => 0, 'in_rank_id' => 0, 'bonus_usd' => 0],
                ['id' => 4, 'sort' => 4, 'alias' => 'manager_silver', 'small_branch_pv' => 8000, 'personal_count' => 0, 'in_rank_count' => 3, 'in_rank_id' => 2, 'bonus_usd' => 0],
            ],
            // rankId => percent (малая ветка)
            'binary_percent_by_rank' => [1 => 5, 2 => 5, 3 => 5, 4 => 5],
            // [packageSort][level] => percent
            'referral_percent' => [
                1 => [1 => 10, 2 => 0],
                2 => [1 => 10, 2 => 5],
                3 => [1 => 10, 2 => 8],
            ],
            // [level][packageId][rankId] => percent
            'leader_percent' => [
                1 => [
                    1 => [2 => 10, 3 => 10, 4 => 10],
                    2 => [2 => 15, 3 => 15, 4 => 15],
                    3 => [2 => 20, 3 => 20, 4 => 20],
                ],
                2 => [
                    3 => [4 => 10],
                ],
            ],
            'global' => ['max_rank_diff' => 2, 'referral_depth' => 2],
        ];
    }

    /**
     * Слить оверрайды поверх дефолтов. Каждая ПРИСУТСТВУЮЩАЯ секция заменяется ЦЕЛИКОМ
     * (не рекурсивно): оверрайд из админки — всегда полный валидированный документ
     * (см. PlanSettingsService), поэтому рекурсивный merge недопустим — он не дал бы
     * удалить ключ (удалённый бонус воскрес бы из дефолтов). Отсутствующая секция берётся
     * из дефолтов (forward-compat для новых секций). Возвращает документ-конфиг (скаляры).
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function mergedConfig(array $overrides = []): array
    {
        $cfg = self::defaults();

        foreach (array_keys($cfg) as $section) {
            if (isset($overrides[$section]) && is_array($overrides[$section])) {
                $cfg[$section] = $overrides[$section];
            }
        }

        return $cfg;
    }

    /**
     * Построить доменный Plan из дефолтов + оверрайдов (документ-конфиг из админки).
     *
     * @param array<string,mixed> $overrides
     */
    public static function fromConfig(array $overrides = []): Plan
    {
        $cfg = self::mergedConfig($overrides);

        $packages = array_map(
            static fn (array $p) => new Package((int) $p['id'], (int) $p['sort'], (string) $p['name'], Pv::fromUnits($p['pv'])),
            $cfg['packages'],
        );

        $ranks = array_map(
            static fn (array $r) => new Rank(
                (int) $r['id'],
                (int) $r['sort'],
                (string) $r['alias'],
                Pv::fromUnits($r['small_branch_pv']),
                (int) $r['personal_count'],
                (int) $r['in_rank_count'],
                (int) $r['in_rank_id'],
                Money::fromDollars($r['bonus_usd']),
            ),
            $cfg['ranks'],
        );

        $binary = [];
        foreach ($cfg['binary_percent_by_rank'] as $rankId => $pct) {
            $binary[(int) $rankId] = Percent::of((float) $pct);
        }

        $referral = [];
        foreach ($cfg['referral_percent'] as $packageSort => $levels) {
            foreach ($levels as $level => $pct) {
                $referral[(int) $packageSort][(int) $level] = Percent::of((float) $pct);
            }
        }

        $leader = [];
        foreach ($cfg['leader_percent'] as $level => $packages2) {
            foreach ($packages2 as $packageId => $ranksMap) {
                foreach ($ranksMap as $rankId => $pct) {
                    $leader[(int) $level][(int) $packageId][(int) $rankId] = Percent::of((float) $pct);
                }
            }
        }

        return new Plan(
            $packages,
            $ranks,
            $binary,
            $referral,
            $leader,
            maxRankDiff: (int) $cfg['global']['max_rank_diff'],
            referralDepth: (int) $cfg['global']['referral_depth'],
        );
    }

    /**
     * BC-обёртка: исторический контракт — оверрайд ранг-бонусов как array<int,Money>.
     * Складывает их в bonus_usd ранга и строит план. Новый код использует fromConfig().
     *
     * @param array<int,Money> $rankBonuses rankId => Money
     */
    public static function create(array $rankBonuses = []): Plan
    {
        if ($rankBonuses === []) {
            return self::fromConfig();
        }

        $ranks = self::defaults()['ranks'];
        foreach ($ranks as &$rank) {
            if (isset($rankBonuses[$rank['id']])) {
                $rank['bonus_usd'] = $rankBonuses[$rank['id']]->dollars();
            }
        }
        unset($rank);

        return self::fromConfig(['ranks' => $ranks]);
    }
}
