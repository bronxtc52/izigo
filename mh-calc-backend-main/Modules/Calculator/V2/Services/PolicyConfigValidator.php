<?php

namespace Modules\Calculator\V2\Services;

use InvalidArgumentException;
use Modules\Calculator\V2\Domain\Policy\QualificationVariantRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;

/**
 * T01: строгая валидация полного документа конфига политики V2 (по образцу
 * PlanSettingsService::validate, но жёстче — деньги-критичный контракт для T02-T12):
 * 12 статусов в каноническом порядке, монотонность порогов малой ветки, ставки bp
 * 0..10000, капы integer-центы >= 0 с верхними границами, half_month == monthly/2
 * (DEC-017), тиры смежные без дыр/пересечений, Σ ставок глобальных пулов == 300 bp,
 * ELITE-лидерские ставки длиной 7, ссылки anchor/support_rank на существующие статусы,
 * unknown keys — ошибка. Бросает InvalidArgumentException (контроллер отдаёт 422).
 */
class PolicyConfigValidator
{
    /** Верхняя граница любых денежных параметров: 100 000 000 USD в центах. */
    private const MAX_CENTS = 10_000_000_000;
    /** Верхняя граница PV-порогов. */
    private const MAX_PV = 100_000_000;
    private const MAX_BP = 10_000;
    private const MAX_COUNT = 1_000_000;
    private const MAX_DAYS = 3_650;

    private const TOP_KEYS = [
        'meta', 'tiers', 'referral', 'statuses', 'leadership', 'global_pool',
        'award', 'grace', 'accounts', 'calibration', 'rank_forever',
    ];

    /** Статусы глобальных пулов в каноническом порядке. */
    private const POOL_STATUSES = [
        'DIRECTOR', 'PEARL_DIRECTOR', 'SAPPHIRE_DIRECTOR', 'DIAMOND_DIRECTOR', 'VICE_PRESIDENT',
    ];

    /** Статусы с квалификационной наградой (без VP — у него транши). */
    private const AWARD_STATUSES = [
        'MANAGER', 'BRONZE_MANAGER', 'SILVER_MANAGER', 'GOLD_MANAGER', 'PLATINUM_MANAGER',
        'DIRECTOR', 'PEARL_DIRECTOR', 'SAPPHIRE_DIRECTOR', 'DIAMOND_DIRECTOR',
    ];

    /**
     * Валидирует документ и возвращает его же (без молчаливых трансформаций —
     * что сохранено, то и провалидировано; hash считается от этого документа).
     */
    public function validate(array $doc): array
    {
        self::assertKeys($doc, self::TOP_KEYS, 'config');

        $this->validateMeta($doc['meta']);
        $this->validateTiers($doc['tiers']);
        $this->validateReferral($doc['referral']);
        $this->validateStatuses($doc['statuses']);
        $this->validateLeadership($doc['leadership'], $doc['statuses']);
        $this->validateGlobalPool($doc['global_pool']);
        $this->validateAward($doc['award']);
        $this->validateGrace($doc['grace']);
        $this->validateAccounts($doc['accounts']);
        $this->validateCalibration($doc['calibration']);

        if ($doc['rank_forever'] !== true) {
            throw new InvalidArgumentException('rank_forever должен быть true (DEC-020: ранг навсегда)');
        }

        return $doc;
    }

    private function validateMeta(mixed $meta): void
    {
        self::assertArray($meta, 'meta');
        self::assertKeys($meta, ['currency', 'kzt_rate', 'timezone'], 'meta');
        if (($meta['currency'] ?? null) !== 'USD') {
            throw new InvalidArgumentException('meta.currency: только USD (решение владельца)');
        }
        self::posInt($meta['kzt_rate'] ?? null, 'meta.kzt_rate', 1_000_000);
        self::str($meta['timezone'] ?? null, 'meta.timezone');
    }

    private function validateTiers(mixed $tiers): void
    {
        self::assertArray($tiers, 'tiers');
        if (count($tiers) < 1) {
            throw new InvalidArgumentException('tiers: пустой список');
        }

        $prevMax = null;
        $codes = [];
        foreach (array_values($tiers) as $i => $tier) {
            $path = "tiers[{$i}]";
            self::assertArray($tier, $path);
            self::assertKeys($tier, ['code', 'min_pv', 'max_pv_exclusive', 'referral_rates_bp'], $path);
            $codes[] = self::str($tier['code'] ?? null, "{$path}.code");

            $min = self::nonNegInt($tier['min_pv'] ?? null, "{$path}.min_pv", self::MAX_PV);
            $max = $tier['max_pv_exclusive'];
            if ($max !== null) {
                $max = self::posInt($max, "{$path}.max_pv_exclusive", self::MAX_PV);
                if ($max <= $min) {
                    throw new InvalidArgumentException("{$path}: max_pv_exclusive <= min_pv");
                }
            } elseif ($i !== count($tiers) - 1) {
                throw new InvalidArgumentException("{$path}: max_pv_exclusive = null допустим только у последнего тира");
            }

            // Смежность без дыр и пересечений: min следующего == max предыдущего.
            if ($prevMax !== null && $min !== $prevMax) {
                throw new InvalidArgumentException("{$path}: тиры не смежны (дыра/пересечение на {$min} vs {$prevMax})");
            }
            $prevMax = $max;

            $rates = $tier['referral_rates_bp'] ?? null;
            self::assertArray($rates, "{$path}.referral_rates_bp");
            self::assertKeys($rates, ['l1', 'l2'], "{$path}.referral_rates_bp");
            self::bp($rates['l1'] ?? null, "{$path}.referral_rates_bp.l1");
            self::bp($rates['l2'] ?? null, "{$path}.referral_rates_bp.l2");
        }
        self::assertUnique($codes, 'tiers.code');
    }

    private function validateReferral(mixed $referral): void
    {
        self::assertArray($referral, 'referral');
        self::assertKeys($referral, ['max_depth', 'stop_at_elite', 'destination', 'trigger'], 'referral');
        self::posInt($referral['max_depth'] ?? null, 'referral.max_depth', 10);
        self::bool($referral['stop_at_elite'] ?? null, 'referral.stop_at_elite');
        self::oneOf($referral['destination'] ?? null, ['OS'], 'referral.destination');
        self::oneOf($referral['trigger'] ?? null, ['ORDER_PAID'], 'referral.trigger');
    }

    private function validateStatuses(mixed $statuses): void
    {
        self::assertArray($statuses, 'statuses');
        $expected = StatusCode::orderedCodes();
        if (count($statuses) !== count($expected)) {
            throw new InvalidArgumentException('statuses: должно быть ровно 12 статусов CLIENT..VICE_PRESIDENT');
        }

        $prevSmallBranch = 0;
        $prevMonthlyCap = 0;
        foreach (array_values($statuses) as $i => $status) {
            $path = "statuses[{$i}]";
            self::assertArray($status, $path);
            self::assertKeys($status, [
                'code', 'ordinal', 'qualification', 'binary_rate_bp',
                'monthly_cap_cents', 'half_month_cap_cents', 'elite_leadership_depth',
            ], $path);

            $code = self::str($status['code'] ?? null, "{$path}.code");
            if ($code !== $expected[$i]) {
                throw new InvalidArgumentException("{$path}.code: ожидается {$expected[$i]} (канонический порядок), получено {$code}");
            }
            $ordinal = self::nonNegInt($status['ordinal'] ?? null, "{$path}.ordinal", 11);
            if ($ordinal !== $i) {
                throw new InvalidArgumentException("{$path}.ordinal: ожидается {$i}, получено {$ordinal}");
            }

            self::bp($status['binary_rate_bp'] ?? null, "{$path}.binary_rate_bp");
            $monthly = self::nonNegInt($status['monthly_cap_cents'] ?? null, "{$path}.monthly_cap_cents", self::MAX_CENTS);
            $half = self::nonNegInt($status['half_month_cap_cents'] ?? null, "{$path}.half_month_cap_cents", self::MAX_CENTS);
            if ($half * 2 !== $monthly) {
                throw new InvalidArgumentException("{$path}: half_month_cap_cents ({$half}) != monthly_cap_cents/2 ({$monthly}) — DEC-017");
            }
            if ($i >= 1 && $monthly < $prevMonthlyCap) {
                throw new InvalidArgumentException("{$path}: monthly_cap_cents не монотонен по лестнице статусов");
            }
            $prevMonthlyCap = max($prevMonthlyCap, $monthly);

            self::nonNegInt($status['elite_leadership_depth'] ?? null, "{$path}.elite_leadership_depth", 7);

            $prevSmallBranch = $this->validateQualification(
                $status['qualification'] ?? null,
                $code,
                $i,
                "{$path}.qualification",
                $prevSmallBranch,
            );
        }
    }

    /** @return int новый максимум small_branch_pv_min (для проверки монотонности) */
    private function validateQualification(mixed $q, string $code, int $ordinal, string $path, int $prevSmallBranch): int
    {
        self::assertArray($q, $path);

        if ($code === 'CLIENT') {
            self::assertKeys($q, ['personal_purchase_pv_min'], $path);
            self::posInt($q['personal_purchase_pv_min'] ?? null, "{$path}.personal_purchase_pv_min", self::MAX_PV);

            return $prevSmallBranch;
        }

        if ($code === 'CONSULTANT') {
            self::assertKeys($q, ['qualified_referrals_min', 'referral_pv_min'], $path);
            self::posInt($q['qualified_referrals_min'] ?? null, "{$path}.qualified_referrals_min", self::MAX_COUNT);
            self::posInt($q['referral_pv_min'] ?? null, "{$path}.referral_pv_min", self::MAX_PV);

            return $prevSmallBranch;
        }

        // MANAGER+ — обязательный порог малой ветки, строго возрастающий.
        $smallBranch = self::posInt($q['small_branch_pv_min'] ?? null, "{$path}.small_branch_pv_min", self::MAX_PV);
        if ($smallBranch <= $prevSmallBranch) {
            throw new InvalidArgumentException("{$path}.small_branch_pv_min: пороги малой ветки должны строго возрастать");
        }

        if (in_array($code, ['MANAGER', 'BRONZE_MANAGER'], true)) {
            self::assertKeys($q, ['small_branch_pv_min', 'direct_referrals_min'], $path);
            self::posInt($q['direct_referrals_min'] ?? null, "{$path}.direct_referrals_min", self::MAX_COUNT);

            return $smallBranch;
        }

        // SILVER_MANAGER+ — варианты с anchor/support-рангами.
        self::assertKeys($q, ['small_branch_pv_min', 'variants'], $path);
        $variants = $q['variants'] ?? null;
        self::assertArray($variants, "{$path}.variants");
        self::assertKeys($variants, ['anchor_rank', 'support_rank', 'options'], "{$path}.variants");

        $anchor = $variants['anchor_rank'] ?? null;
        $support = $variants['support_rank'];
        $this->assertLowerStatusRef($anchor, $ordinal, "{$path}.variants.anchor_rank");
        if ($support !== null) {
            $this->assertLowerStatusRef($support, $ordinal, "{$path}.variants.support_rank");
        }

        $options = $variants['options'] ?? null;
        self::assertArray($options, "{$path}.variants.options");
        if (count($options) < 1) {
            throw new InvalidArgumentException("{$path}.variants.options: нужен хотя бы один вариант");
        }
        $optionCodes = [];
        foreach (array_values($options) as $j => $option) {
            $optPath = "{$path}.variants.options[{$j}]";
            self::assertArray($option, $optPath);
            self::assertKeys($option, ['code', 'anchor_count', 'support_count', 'comparator', 'distinct_root_branches'], $optPath);
            $optionCodes[] = self::str($option['code'] ?? null, "{$optPath}.code");
            $anchorCount = self::nonNegInt($option['anchor_count'] ?? null, "{$optPath}.anchor_count", self::MAX_COUNT);
            $supportCount = self::nonNegInt($option['support_count'] ?? null, "{$optPath}.support_count", self::MAX_COUNT);
            if ($anchorCount + $supportCount < 1) {
                throw new InvalidArgumentException("{$optPath}: вариант без единого требуемого лидера");
            }
            if ($supportCount > 0 && $support === null) {
                throw new InvalidArgumentException("{$optPath}: support_count > 0 при support_rank = null");
            }
            self::oneOf($option['comparator'] ?? null, [
                QualificationVariantRule::COMPARATOR_EXACT,
                QualificationVariantRule::COMPARATOR_AT_LEAST,
            ], "{$optPath}.comparator");
            self::bool($option['distinct_root_branches'] ?? null, "{$optPath}.distinct_root_branches");
        }
        self::assertUnique($optionCodes, "{$path}.variants.options.code");

        return $smallBranch;
    }

    /** Ссылка anchor/support_rank: существующий статус СТРОГО ниже квалифицируемого. */
    private function assertLowerStatusRef(mixed $ref, int $statusOrdinal, string $path): void
    {
        $code = self::str($ref, $path);
        $target = StatusCode::tryFrom($code);
        if ($target === null) {
            throw new InvalidArgumentException("{$path}: ссылка на несуществующий статус {$code}");
        }
        if ($target->ordinal() >= $statusOrdinal) {
            throw new InvalidArgumentException("{$path}: {$code} не ниже квалифицируемого статуса");
        }
    }

    private function validateLeadership(mixed $leadership, array $statuses): void
    {
        self::assertArray($leadership, 'leadership');
        self::assertKeys($leadership, ['eligibility_status_min', 'base', 'rank_gap_block_ordinal_diff', 'tiers'], 'leadership');

        $min = self::str($leadership['eligibility_status_min'] ?? null, 'leadership.eligibility_status_min');
        if (StatusCode::tryFrom($min) === null) {
            throw new InvalidArgumentException("leadership.eligibility_status_min: несуществующий статус {$min}");
        }
        self::oneOf($leadership['base'] ?? null, ['PAID_AFTER_CAPS_AND_POOL'], 'leadership.base');
        self::posInt($leadership['rank_gap_block_ordinal_diff'] ?? null, 'leadership.rank_gap_block_ordinal_diff', 11);

        $tiers = $leadership['tiers'] ?? null;
        self::assertArray($tiers, 'leadership.tiers');
        self::assertKeys($tiers, ['START', 'BUSINESS', 'ELITE'], 'leadership.tiers');

        foreach (['START', 'BUSINESS'] as $tier) {
            $t = $tiers[$tier] ?? null;
            self::assertArray($t, "leadership.tiers.{$tier}");
            self::assertKeys($t, ['depth', 'rates_bp'], "leadership.tiers.{$tier}");
            $depth = self::posInt($t['depth'] ?? null, "leadership.tiers.{$tier}.depth", 7);
            $this->validateRatesList($t['rates_bp'] ?? null, "leadership.tiers.{$tier}.rates_bp", $depth);
        }

        $elite = $tiers['ELITE'] ?? null;
        self::assertArray($elite, 'leadership.tiers.ELITE');
        self::assertKeys($elite, ['depth_source', 'max_depth', 'rates_bp'], 'leadership.tiers.ELITE');
        self::oneOf($elite['depth_source'] ?? null, ['STATUS'], 'leadership.tiers.ELITE.depth_source');
        $maxDepth = self::posInt($elite['max_depth'] ?? null, 'leadership.tiers.ELITE.max_depth', 7);
        $this->validateRatesList($elite['rates_bp'] ?? null, 'leadership.tiers.ELITE.rates_bp', $maxDepth);

        // elite_leadership_depth статусов не должен превышать max_depth (иначе нет ставки).
        foreach (array_values($statuses) as $i => $status) {
            $depth = $status['elite_leadership_depth'] ?? 0;
            if (is_int($depth) && $depth > $maxDepth) {
                throw new InvalidArgumentException("statuses[{$i}].elite_leadership_depth > leadership.tiers.ELITE.max_depth");
            }
        }
    }

    private function validateRatesList(mixed $rates, string $path, int $expectedLen): void
    {
        self::assertArray($rates, $path);
        if (!array_is_list($rates) || count($rates) !== $expectedLen) {
            throw new InvalidArgumentException("{$path}: ожидается список ставок длиной {$expectedLen}");
        }
        foreach ($rates as $j => $rate) {
            self::bp($rate, "{$path}[{$j}]");
        }
    }

    private function validateGlobalPool(mixed $pool): void
    {
        self::assertArray($pool, 'global_pool');
        self::assertKeys($pool, [
            'pools', 'max_shares', 'member_cap_bp', 'remainder', 'accrual', 'payout',
            'quarter_mode', 'inherits_lower_pools', 'include_personal_pv',
        ], 'global_pool');

        $pools = $pool['pools'] ?? null;
        self::assertArray($pools, 'global_pool.pools');
        if (count($pools) !== count(self::POOL_STATUSES)) {
            throw new InvalidArgumentException('global_pool.pools: ожидается ровно 5 пулов Director..VP');
        }
        $sum = 0;
        foreach (array_values($pools) as $i => $p) {
            $path = "global_pool.pools[{$i}]";
            self::assertArray($p, $path);
            self::assertKeys($p, ['status', 'rate_bp', 'one_share_pv_min'], $path);
            $status = self::str($p['status'] ?? null, "{$path}.status");
            if ($status !== self::POOL_STATUSES[$i]) {
                throw new InvalidArgumentException("{$path}.status: ожидается " . self::POOL_STATUSES[$i] . " (канонический порядок)");
            }
            $sum += self::bp($p['rate_bp'] ?? null, "{$path}.rate_bp");
            self::posInt($p['one_share_pv_min'] ?? null, "{$path}.one_share_pv_min", self::MAX_PV);
        }
        if ($sum !== 300) {
            throw new InvalidArgumentException("global_pool: сумма ставок пулов {$sum} bp != 300 bp (3%)");
        }

        self::posInt($pool['max_shares'] ?? null, 'global_pool.max_shares', 100);
        self::bp($pool['member_cap_bp'] ?? null, 'global_pool.member_cap_bp');
        self::oneOf($pool['remainder'] ?? null, ['COMPANY_UNALLOCATED'], 'global_pool.remainder');
        self::oneOf($pool['accrual'] ?? null, ['MONTH'], 'global_pool.accrual');
        self::oneOf($pool['payout'] ?? null, ['QUARTER'], 'global_pool.payout');
        self::oneOf($pool['quarter_mode'] ?? null, ['CALENDAR', 'FISCAL'], 'global_pool.quarter_mode');
        self::bool($pool['inherits_lower_pools'] ?? null, 'global_pool.inherits_lower_pools');
        self::bool($pool['include_personal_pv'] ?? null, 'global_pool.include_personal_pv');
    }

    private function validateAward(mixed $award): void
    {
        self::assertArray($award, 'award');
        self::assertKeys($award, ['destination', 'on_rank_jump', 'by_status_cents', 'vp_tranches'], 'award');
        self::oneOf($award['destination'] ?? null, ['BS'], 'award.destination');
        self::oneOf($award['on_rank_jump'] ?? null, ['ALL_CROSSED'], 'award.on_rank_jump');

        $byStatus = $award['by_status_cents'] ?? null;
        self::assertArray($byStatus, 'award.by_status_cents');
        self::assertKeys($byStatus, self::AWARD_STATUSES, 'award.by_status_cents');
        foreach (self::AWARD_STATUSES as $code) {
            self::nonNegInt($byStatus[$code] ?? null, "award.by_status_cents.{$code}", self::MAX_CENTS);
        }

        $tranches = $award['vp_tranches'] ?? null;
        self::assertArray($tranches, 'award.vp_tranches');
        if (!array_is_list($tranches) || count($tranches) !== 3) {
            throw new InvalidArgumentException('award.vp_tranches: ожидается ровно 3 транша VP');
        }
        foreach (array_values($tranches) as $i => $tranche) {
            $path = "award.vp_tranches[{$i}]";
            self::assertArray($tranche, $path);
            self::assertKeys($tranche, ['sequence', 'amount_cents', 'trigger'], $path);
            $seq = self::posInt($tranche['sequence'] ?? null, "{$path}.sequence", 3);
            if ($seq !== $i + 1) {
                throw new InvalidArgumentException("{$path}.sequence: ожидается " . ($i + 1));
            }
            self::nonNegInt($tranche['amount_cents'] ?? null, "{$path}.amount_cents", self::MAX_CENTS);
            self::str($tranche['trigger'] ?? null, "{$path}.trigger");
        }
    }

    private function validateGrace(mixed $grace): void
    {
        self::assertArray($grace, 'grace');
        self::assertKeys($grace, ['client_to_consultant_days'], 'grace');
        self::posInt($grace['client_to_consultant_days'] ?? null, 'grace.client_to_consultant_days', self::MAX_DAYS);
    }

    private function validateAccounts(mixed $accounts): void
    {
        self::assertArray($accounts, 'accounts');
        self::assertKeys($accounts, ['os', 'ns', 'bs', 'lot_consumption', 'internal_funding_full_bv'], 'accounts');

        $os = $accounts['os'] ?? null;
        self::assertArray($os, 'accounts.os');
        self::assertKeys($os, ['withdrawable', 'max_order_payment_share_bp', 'lot_lifetime_days', 'on_expiry'], 'accounts.os');
        self::bool($os['withdrawable'] ?? null, 'accounts.os.withdrawable');
        self::bp($os['max_order_payment_share_bp'] ?? null, 'accounts.os.max_order_payment_share_bp');
        self::posInt($os['lot_lifetime_days'] ?? null, 'accounts.os.lot_lifetime_days', self::MAX_DAYS);
        self::oneOf($os['on_expiry'] ?? null, ['TRANSFER_TO_BS'], 'accounts.os.on_expiry');

        $ns = $accounts['ns'] ?? null;
        self::assertArray($ns, 'accounts.ns');
        self::assertKeys($ns, ['transfer_days', 'transfer_to'], 'accounts.ns');
        $days = $ns['transfer_days'] ?? null;
        self::assertArray($days, 'accounts.ns.transfer_days');
        if (!array_is_list($days) || count($days) < 1) {
            throw new InvalidArgumentException('accounts.ns.transfer_days: непустой список дней месяца');
        }
        foreach ($days as $j => $day) {
            $d = self::posInt($day, "accounts.ns.transfer_days[{$j}]", 28);
            unset($d);
        }
        self::oneOf($ns['transfer_to'] ?? null, ['OS'], 'accounts.ns.transfer_to');

        $bs = $accounts['bs'] ?? null;
        self::assertArray($bs, 'accounts.bs');
        self::assertKeys($bs, ['withdrawable', 'purchasable', 'lot_lifetime_days', 'on_expiry'], 'accounts.bs');
        if (($bs['withdrawable'] ?? null) !== false) {
            throw new InvalidArgumentException('accounts.bs.withdrawable: БС невыводим by design');
        }
        self::bool($bs['purchasable'] ?? null, 'accounts.bs.purchasable');
        self::posInt($bs['lot_lifetime_days'] ?? null, 'accounts.bs.lot_lifetime_days', self::MAX_DAYS);
        self::oneOf($bs['on_expiry'] ?? null, ['FORFEIT'], 'accounts.bs.on_expiry');

        self::oneOf($accounts['lot_consumption'] ?? null, ['EARLIEST_EXPIRY_FIRST'], 'accounts.lot_consumption');
        self::bool($accounts['internal_funding_full_bv'] ?? null, 'accounts.internal_funding_full_bv');
    }

    private function validateCalibration(mixed $calibration): void
    {
        self::assertArray($calibration, 'calibration');
        self::assertKeys($calibration, ['rate_bp', 'mode', 'base', 'include'], 'calibration');
        self::bp($calibration['rate_bp'] ?? null, 'calibration.rate_bp');
        self::oneOf($calibration['mode'] ?? null, ['SCALE_DOWN_ONLY'], 'calibration.mode');
        self::oneOf($calibration['base'] ?? null, ['PERIOD_BV'], 'calibration.base');

        $include = $calibration['include'] ?? null;
        self::assertArray($include, 'calibration.include');
        $includeKeys = ['structure_after_caps', 'referral', 'global_pool_monthly', 'leadership', 'awards'];
        self::assertKeys($include, $includeKeys, 'calibration.include');
        foreach ($includeKeys as $key) {
            self::bool($include[$key] ?? null, "calibration.include.{$key}");
        }
        // Amendments MF-1/2: лидерский НЕ входит в числитель (DEC-029, иначе цикл).
        if ($include['leadership'] !== false) {
            throw new InvalidArgumentException('calibration.include.leadership: должен быть false (amendments MF-1/2, DEC-029)');
        }
    }

    // --- примитивы ---

    private static function assertArray(mixed $value, string $path): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("{$path}: ожидается объект/массив");
        }
    }

    /** Ровно эти ключи: отсутствующий или неизвестный ключ — ошибка. */
    private static function assertKeys(array $value, array $keys, string $path): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException("{$path}: отсутствует ключ {$key}");
            }
        }
        $unknown = array_diff(array_keys($value), $keys);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$path}: неизвестные ключи: " . implode(', ', $unknown));
        }
    }

    private static function assertUnique(array $values, string $path): void
    {
        if (count($values) !== count(array_unique($values))) {
            throw new InvalidArgumentException("{$path}: дубли не допускаются");
        }
    }

    private static function str(mixed $value, string $path): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$path}: ожидается непустая строка");
        }

        return $value;
    }

    private static function oneOf(mixed $value, array $allowed, string $path): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("{$path}: допустимые значения — " . implode('|', $allowed));
        }

        return $value;
    }

    private static function bool(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw new InvalidArgumentException("{$path}: ожидается bool");
        }

        return $value;
    }

    /** Строго integer (не float, не numeric-string) в [0, max] — деньги/PV/счётчики. */
    private static function nonNegInt(mixed $value, string $path, int $max): int
    {
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("{$path}: ожидается целое >= 0");
        }
        if ($value > $max) {
            throw new InvalidArgumentException("{$path}: {$value} превышает верхнюю границу {$max}");
        }

        return $value;
    }

    private static function posInt(mixed $value, string $path, int $max): int
    {
        $int = self::nonNegInt($value, $path, $max);
        if ($int < 1) {
            throw new InvalidArgumentException("{$path}: ожидается целое >= 1");
        }

        return $int;
    }

    /** Ставка в basis points: integer 0..10000. */
    private static function bp(mixed $value, string $path): int
    {
        return self::nonNegInt($value, $path, self::MAX_BP);
    }
}
