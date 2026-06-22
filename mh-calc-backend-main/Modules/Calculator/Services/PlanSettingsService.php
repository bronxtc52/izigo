<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Domain\Plan\IziGoPlanFactory;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Repositories\EloquentPlanRepository;

/**
 * Редактирование маркетинг-плана из веб-админки: чтение полного документа (дефолты +
 * оверрайды) и запись нового документа в plan_settings('plan') с валидацией и аудитом.
 *
 * Forward-only: смена плана влияет только на БУДУЩИЕ активации (комп-движок читает план
 * на момент расчёта); прошлые начисления не пересчитываются. Источник правды — боевое
 * доменное ядро ({@see IziGoPlanFactory}); легаси-симулятор вне скоупа.
 */
class PlanSettingsService
{
    public function __construct(
        private readonly EloquentPlanRepository $planRepository,
        private readonly AuditLogService $audit,
    ) {
    }

    /** Полный текущий документ плана (дефолты + оверрайды) для редактирования в UI. */
    public function current(): array
    {
        return IziGoPlanFactory::mergedConfig($this->planRepository->overridesFromSettings());
    }

    /**
     * Заменить документ плана. Валидирует диапазоны/структуру/ссылочную целостность,
     * пишет в plan_settings и аудит-лог (before→after) — АТОМАРНО в одной транзакции под
     * локом строки `plan` (деньги-критичный конфиг: либо и мутация, и аудит, либо ничего;
     * параллельные правки сериализуются). Возвращает новый текущий документ.
     */
    public function update(array $doc, ?int $actorId): array
    {
        $clean = $this->validate($doc);

        return DB::transaction(function () use ($clean, $actorId) {
            // Лок строки plan (или точки её создания) — сериализует конкурентные PUT.
            PlanSetting::query()->where('key', 'plan')->lockForUpdate()->first();

            $before = $this->current();
            PlanSetting::put('plan', $clean);
            $after = $this->current();

            $this->audit->record($actorId, 'plan.update', 'plan', null, $before, $after);

            return $after;
        });
    }

    /** Здравые верхние границы (конфиг компании, не пользовательский ввод, но деньги-критично). */
    private const MAX_LIST = 200;
    private const MAX_PV = 10_000_000;
    private const MAX_BONUS_USD = 10_000_000;
    private const MAX_COUNT = 1_000_000;

    /**
     * Валидация полного документа плана: все секции, диапазоны (проценты 0–100, объёмы/
     * счётчики ≥0 с верхними границами), уникальность id, и ССЫЛОЧНАЯ ЦЕЛОСТНОСТЬ матриц
     * (binary/referral/leader ссылаются только на существующие ранги/пакеты). Иначе owner
     * «задаёт» бонус, которого движок не начислит. Бросает InvalidArgumentException (→ 422).
     */
    private function validate(array $doc): array
    {
        foreach (['packages', 'ranks', 'binary_percent_by_rank', 'referral_percent', 'leader_percent', 'global'] as $section) {
            if (!array_key_exists($section, $doc)) {
                throw new InvalidArgumentException("Отсутствует секция плана: {$section}");
            }
        }

        $packages = $this->validateList($doc['packages'], 'packages', static function (array $p): array {
            return [
                'id' => self::posInt($p['id'] ?? null, 'packages.id'),
                'sort' => self::posInt($p['sort'] ?? null, 'packages.sort'),
                'name' => self::str($p['name'] ?? null, 'packages.name'),
                'pv' => self::nonNegNum($p['pv'] ?? null, 'packages.pv', self::MAX_PV),
            ];
        });

        $ranks = $this->validateList($doc['ranks'], 'ranks', static function (array $r): array {
            return [
                'id' => self::posInt($r['id'] ?? null, 'ranks.id'),
                'sort' => self::posInt($r['sort'] ?? null, 'ranks.sort'),
                'alias' => self::str($r['alias'] ?? null, 'ranks.alias'),
                'small_branch_pv' => self::nonNegNum($r['small_branch_pv'] ?? null, 'ranks.small_branch_pv', self::MAX_PV),
                'personal_count' => self::nonNegInt($r['personal_count'] ?? null, 'ranks.personal_count', self::MAX_COUNT),
                'in_rank_count' => self::nonNegInt($r['in_rank_count'] ?? null, 'ranks.in_rank_count', self::MAX_COUNT),
                'in_rank_id' => self::nonNegInt($r['in_rank_id'] ?? null, 'ranks.in_rank_id', self::MAX_COUNT),
                'bonus_usd' => self::nonNegNum($r['bonus_usd'] ?? null, 'ranks.bonus_usd', self::MAX_BONUS_USD),
            ];
        });

        // Уникальность идентификаторов — иначе Plan молча схлопнет дубли (packagesById/ранги).
        $packageIds = array_column($packages, 'id');
        $packageSorts = array_column($packages, 'sort');
        $rankIds = array_column($ranks, 'id');
        self::assertUnique($packageIds, 'packages.id');
        self::assertUnique($packageSorts, 'packages.sort');
        self::assertUnique($rankIds, 'ranks.id');

        // Ссылочная целостность процентных матриц.
        $binary = $this->validatePercentMap($doc['binary_percent_by_rank'], 'binary_percent_by_rank', 1);
        self::assertKeysSubset(array_keys($binary), $rankIds, 'binary_percent_by_rank (rankId)');

        $referral = $this->validatePercentMap($doc['referral_percent'], 'referral_percent', 2);
        self::assertKeysSubset(array_keys($referral), $packageSorts, 'referral_percent (packageSort)');

        $leader = $this->validatePercentMap($doc['leader_percent'], 'leader_percent', 3);
        foreach ($leader as $level => $byPackage) {
            self::assertKeysSubset(array_keys($byPackage), $packageIds, "leader_percent[{$level}] (packageId)");
            foreach ($byPackage as $packageId => $byRank) {
                self::assertKeysSubset(array_keys($byRank), $rankIds, "leader_percent[{$level}][{$packageId}] (rankId)");
            }
        }

        return [
            'packages' => $packages,
            'ranks' => $ranks,
            'binary_percent_by_rank' => $binary,
            'referral_percent' => $referral,
            'leader_percent' => $leader,
            'global' => [
                // max_rank_diff ≥ 1: при 0 rank-compression отрезает лидерские бонусы целиком.
                'max_rank_diff' => self::posInt($doc['global']['max_rank_diff'] ?? null, 'global.max_rank_diff'),
                'referral_depth' => self::posInt($doc['global']['referral_depth'] ?? null, 'global.referral_depth'),
            ],
        ];
    }

    private function validateList(mixed $list, string $name, callable $row): array
    {
        if (!is_array($list) || $list === []) {
            throw new InvalidArgumentException("Секция {$name} должна быть непустым списком");
        }
        if (count($list) > self::MAX_LIST) {
            throw new InvalidArgumentException("Секция {$name}: слишком много элементов (макс " . self::MAX_LIST . ')');
        }

        return array_map(static fn ($item) => $row(is_array($item) ? $item : []), array_values($list));
    }

    /** @param array<int|string> $values */
    private static function assertUnique(array $values, string $field): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException("{$field}: значения должны быть уникальны");
        }
    }

    /**
     * @param array<int> $keys
     * @param array<int> $allowed
     */
    private static function assertKeysSubset(array $keys, array $allowed, string $field): void
    {
        $unknown = array_diff($keys, $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$field}: ссылка на несуществующий id: " . implode(',', $unknown));
        }
    }

    /**
     * Рекурсивная карта процентов глубины $depth (листья — проценты 0–100), ключи
     * приводятся к int. Используется для binary(1)/referral(2)/leader(3).
     */
    private function validatePercentMap(mixed $map, string $name, int $depth): array
    {
        if (!is_array($map)) {
            throw new InvalidArgumentException("Секция {$name} должна быть картой");
        }

        $out = [];
        foreach ($map as $key => $value) {
            $intKey = self::posInt($key, "{$name}.key");
            if ($depth === 1) {
                $out[$intKey] = self::percent($value, "{$name}.{$key}");
            } else {
                $out[$intKey] = $this->validatePercentMap($value, "{$name}.{$key}", $depth - 1);
            }
        }

        return $out;
    }

    private static function percent(mixed $v, string $field): float
    {
        if (!is_numeric($v) || $v < 0 || $v > 100) {
            throw new InvalidArgumentException("{$field}: процент должен быть числом 0–100");
        }

        return (float) $v;
    }

    private static function nonNegNum(mixed $v, string $field, ?float $max = null): float
    {
        if (!is_numeric($v) || $v < 0) {
            throw new InvalidArgumentException("{$field}: число ≥ 0");
        }
        if ($max !== null && $v > $max) {
            throw new InvalidArgumentException("{$field}: превышен предел {$max}");
        }

        return (float) $v;
    }

    private static function nonNegInt(mixed $v, string $field, ?int $max = null): int
    {
        if (!is_numeric($v) || (int) $v != $v || $v < 0) {
            throw new InvalidArgumentException("{$field}: целое ≥ 0");
        }
        if ($max !== null && $v > $max) {
            throw new InvalidArgumentException("{$field}: превышен предел {$max}");
        }

        return (int) $v;
    }

    private static function posInt(mixed $v, string $field): int
    {
        if (!is_numeric($v) || (int) $v != $v || $v < 1) {
            throw new InvalidArgumentException("{$field}: целое ≥ 1");
        }

        return (int) $v;
    }

    private static function str(mixed $v, string $field): string
    {
        if (!is_string($v) || trim($v) === '') {
            throw new InvalidArgumentException("{$field}: непустая строка");
        }

        return $v;
    }
}
