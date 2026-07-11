<?php

namespace Modules\Calculator\V2\Services;

/**
 * T01: канонический PHP-массив конфига политики MH V2 (единственный источник:
 * сид-миграция 2026_07_12_100100 + golden-тесты). Референс — 07_Rules_Config.example.yaml
 * спеки Marine_Health_Technical_Spec 2 (YAML-зависимость НЕ добавляется).
 *
 * Все деньги — integer USD-центы (курс 468 KZT = 1 USD применён здесь, дальше KZT
 * не существует), ставки — integer basis points, PV-пороги — integer.
 *
 * Вшитые решения владельца (Гейт 0/A, docs/specs/2026-07-12-mh-full-plan-dec-triage.md):
 * подписка отсутствует (DEC-004), скидка MH отсутствует, ранг навсегда (DEC-020),
 * referral_stop_at_elite = FALSE (реферальная платится всегда), 60% полная калибровка
 * scale-down-only (DEC-014 + amendments MF-1/2: лидерский и награды НЕ в числителе),
 * max долей глобального = 2 (DEC-032), все пройденные награды при скачке (DEC-040).
 */
final class DefaultPolicyConfig
{
    public const CODE = 'mh-v2-usd-1';
    public const SCHEMA_VERSION = 1;

    /** sha256 канонического JSON (рекурсивный ksort) — стабилен к порядку ключей. */
    public static function canonicalHash(array $doc): string
    {
        $canonical = self::ksortRecursive($doc);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function ksortRecursive(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::ksortRecursive($v);
            }
        }
        // Списки (sequential) не пересортировываем — порядок значим (ставки по уровням).
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public static function doc(): array
    {
        return [
            'meta' => [
                'currency' => 'USD',
                'kzt_rate' => 468,
                'timezone' => 'UTC',
            ],

            // Тиры контракта по НАКОПЛЕННОМУ personal PV; реферальные ставки — по тиру
            // получателя (T07). Тир не понижается. Bronze-тариф поднят до 100 PV на
            // cutover (решение Гейта A) — каждый покупатель дотягивается до START.
            'tiers' => [
                ['code' => 'START', 'min_pv' => 100, 'max_pv_exclusive' => 200, 'referral_rates_bp' => ['l1' => 1000, 'l2' => 0]],
                ['code' => 'BUSINESS', 'min_pv' => 200, 'max_pv_exclusive' => 600, 'referral_rates_bp' => ['l1' => 1000, 'l2' => 500]],
                ['code' => 'ELITE', 'min_pv' => 600, 'max_pv_exclusive' => null, 'referral_rates_bp' => ['l1' => 1000, 'l2' => 800]],
            ],

            'referral' => [
                'max_depth' => 2,
                // Решение владельца 2026-07-12: стоп на ELITE ОТКЛЮЧЁН, платится всегда.
                'stop_at_elite' => false,
                'destination' => 'OS',
                'trigger' => 'ORDER_PAID',
            ],

            // 12 статусов CLIENT..VICE_PRESIDENT (ordinal 0..11). Капы месячные и
            // полумесячные (= monthly/2 ЯВНО, DEC-017) — integer USD-центы.
            // elite_leadership_depth — глубина лидерского для ELITE-получателя (T08).
            'statuses' => [
                [
                    'code' => 'CLIENT', 'ordinal' => 0,
                    'qualification' => ['personal_purchase_pv_min' => 100],
                    'binary_rate_bp' => 0, 'monthly_cap_cents' => 0, 'half_month_cap_cents' => 0,
                    'elite_leadership_depth' => 0,
                ],
                [
                    'code' => 'CONSULTANT', 'ordinal' => 1,
                    'qualification' => ['qualified_referrals_min' => 1, 'referral_pv_min' => 100],
                    'binary_rate_bp' => 500, 'monthly_cap_cents' => 50000, 'half_month_cap_cents' => 25000,
                    'elite_leadership_depth' => 0,
                ],
                [
                    'code' => 'MANAGER', 'ordinal' => 2,
                    'qualification' => ['small_branch_pv_min' => 1000, 'direct_referrals_min' => 4],
                    'binary_rate_bp' => 500, 'monthly_cap_cents' => 100000, 'half_month_cap_cents' => 50000,
                    'elite_leadership_depth' => 1,
                ],
                [
                    'code' => 'BRONZE_MANAGER', 'ordinal' => 3,
                    'qualification' => ['small_branch_pv_min' => 3000, 'direct_referrals_min' => 8],
                    'binary_rate_bp' => 500, 'monthly_cap_cents' => 150000, 'half_month_cap_cents' => 75000,
                    'elite_leadership_depth' => 1,
                ],
                [
                    // Спека (07_Rules_Config): Silver = малая ветка 8000 PV + 3 лидера
                    // ранга MANAGER на 1-й линии → единственный вариант V1 anchor_count=3.
                    'code' => 'SILVER_MANAGER', 'ordinal' => 4,
                    'qualification' => [
                        'small_branch_pv_min' => 8000,
                        'variants' => [
                            'anchor_rank' => 'MANAGER',
                            'support_rank' => null,
                            'options' => [
                                ['code' => 'V1', 'anchor_count' => 3, 'support_count' => 0, 'comparator' => 'at_least', 'distinct_root_branches' => false],
                            ],
                        ],
                    ],
                    'binary_rate_bp' => 500, 'monthly_cap_cents' => 200000, 'half_month_cap_cents' => 100000,
                    'elite_leadership_depth' => 2,
                ],
                [
                    'code' => 'GOLD_MANAGER', 'ordinal' => 5,
                    'qualification' => [
                        'small_branch_pv_min' => 20000,
                        'variants' => self::variants('SILVER_MANAGER', 'BRONZE_MANAGER'),
                    ],
                    'binary_rate_bp' => 600, 'monthly_cap_cents' => 500000, 'half_month_cap_cents' => 250000,
                    'elite_leadership_depth' => 3,
                ],
                [
                    'code' => 'PLATINUM_MANAGER', 'ordinal' => 6,
                    'qualification' => [
                        'small_branch_pv_min' => 60000,
                        'variants' => self::variants('GOLD_MANAGER', 'SILVER_MANAGER'),
                    ],
                    'binary_rate_bp' => 600, 'monthly_cap_cents' => 1000000, 'half_month_cap_cents' => 500000,
                    'elite_leadership_depth' => 4,
                ],
                [
                    'code' => 'DIRECTOR', 'ordinal' => 7,
                    'qualification' => [
                        'small_branch_pv_min' => 150000,
                        'variants' => self::variants('PLATINUM_MANAGER', 'GOLD_MANAGER'),
                    ],
                    'binary_rate_bp' => 700, 'monthly_cap_cents' => 1500000, 'half_month_cap_cents' => 750000,
                    'elite_leadership_depth' => 5,
                ],
                [
                    'code' => 'PEARL_DIRECTOR', 'ordinal' => 8,
                    'qualification' => [
                        'small_branch_pv_min' => 380000,
                        'variants' => self::variants('DIRECTOR', 'PLATINUM_MANAGER'),
                    ],
                    'binary_rate_bp' => 700, 'monthly_cap_cents' => 2500000, 'half_month_cap_cents' => 1250000,
                    'elite_leadership_depth' => 6,
                ],
                [
                    'code' => 'SAPPHIRE_DIRECTOR', 'ordinal' => 9,
                    'qualification' => [
                        'small_branch_pv_min' => 760000,
                        'variants' => self::variants('PEARL_DIRECTOR', 'DIRECTOR'),
                    ],
                    'binary_rate_bp' => 800, 'monthly_cap_cents' => 3000000, 'half_month_cap_cents' => 1500000,
                    'elite_leadership_depth' => 7,
                ],
                [
                    'code' => 'DIAMOND_DIRECTOR', 'ordinal' => 10,
                    'qualification' => [
                        'small_branch_pv_min' => 1500000,
                        'variants' => self::variants('SAPPHIRE_DIRECTOR', 'PEARL_DIRECTOR'),
                    ],
                    'binary_rate_bp' => 800, 'monthly_cap_cents' => 3500000, 'half_month_cap_cents' => 1750000,
                    'elite_leadership_depth' => 7,
                ],
                [
                    'code' => 'VICE_PRESIDENT', 'ordinal' => 11,
                    'qualification' => [
                        'small_branch_pv_min' => 3000000,
                        'variants' => self::variants('DIAMOND_DIRECTOR', 'SAPPHIRE_DIRECTOR'),
                    ],
                    'binary_rate_bp' => 900, 'monthly_cap_cents' => 4000000, 'half_month_cap_cents' => 2000000,
                    'elite_leadership_depth' => 7,
                ],
            ],

            'leadership' => [
                'eligibility_status_min' => 'MANAGER',
                // DEC-029: база = фактически выплаченная структурная премия даунлайна
                // ПОСЛЕ капов и 60%-калибровки.
                'base' => 'PAID_AFTER_CAPS_AND_POOL',
                // DEC-030 / amendments MF-11: «блок без передачи» при разнице статусов >= 3.
                'rank_gap_block_ordinal_diff' => 3,
                'tiers' => [
                    'START' => ['depth' => 1, 'rates_bp' => [1000]],
                    'BUSINESS' => ['depth' => 1, 'rates_bp' => [1500]],
                    'ELITE' => ['depth_source' => 'STATUS', 'max_depth' => 7, 'rates_bp' => [2000, 1000, 500, 300, 100, 100, 100]],
                ],
            ],

            'global_pool' => [
                // Ставки от месячного global BV; Σ = 300 bp (3%). База доли — PV
                // реферального дерева за месяц; доли = min(floor(PV/base), max_shares).
                'pools' => [
                    ['status' => 'DIRECTOR', 'rate_bp' => 100, 'one_share_pv_min' => 100000],
                    ['status' => 'PEARL_DIRECTOR', 'rate_bp' => 75, 'one_share_pv_min' => 400000],
                    ['status' => 'SAPPHIRE_DIRECTOR', 'rate_bp' => 50, 'one_share_pv_min' => 1000000],
                    ['status' => 'DIAMOND_DIRECTOR', 'rate_bp' => 50, 'one_share_pv_min' => 3000000],
                    ['status' => 'VICE_PRESIDENT', 'rate_bp' => 25, 'one_share_pv_min' => 6000000],
                ],
                'max_shares' => 2,            // DEC-032
                'member_cap_bp' => 2500,      // кап 25% пула на участника
                'remainder' => 'COMPANY_UNALLOCATED', // DEC-034
                'accrual' => 'MONTH',
                'payout' => 'QUARTER',
                'quarter_mode' => 'CALENDAR', // DEC-036
                'inherits_lower_pools' => true,
                'include_personal_pv' => true, // дефолт Гейта A
            ],

            'award' => [
                'destination' => 'BS',
                'on_rank_jump' => 'ALL_CROSSED', // DEC-040
                // USD-центы: 100/200/300/500/1500/2500/20000/35000/53000 USD.
                'by_status_cents' => [
                    'MANAGER' => 10000,
                    'BRONZE_MANAGER' => 20000,
                    'SILVER_MANAGER' => 30000,
                    'GOLD_MANAGER' => 50000,
                    'PLATINUM_MANAGER' => 150000,
                    'DIRECTOR' => 250000,
                    'PEARL_DIRECTOR' => 2000000,
                    'SAPPHIRE_DIRECTOR' => 3500000,
                    'DIAMOND_DIRECTOR' => 5300000,
                ],
                // VP 150 000 USD тремя траншами по 50 000 (DEC-042: этапы 2-3 — по
                // квалификациям глобального бонуса).
                'vp_tranches' => [
                    ['sequence' => 1, 'amount_cents' => 5000000, 'trigger' => 'STATUS_ACHIEVED'],
                    ['sequence' => 2, 'amount_cents' => 5000000, 'trigger' => 'FIRST_VP_GLOBAL_BONUS_QUALIFICATION'],
                    ['sequence' => 3, 'amount_cents' => 5000000, 'trigger' => 'SECOND_VP_GLOBAL_BONUS_QUALIFICATION'],
                ],
            ],

            'grace' => [
                'client_to_consultant_days' => 30,
            ],

            'accounts' => [
                'os' => [
                    'withdrawable' => true,
                    'max_order_payment_share_bp' => 7000,
                    'lot_lifetime_days' => 365,
                    'on_expiry' => 'TRANSFER_TO_BS',
                ],
                'ns' => [
                    'transfer_days' => [1, 16],
                    'transfer_to' => 'OS',
                ],
                'bs' => [
                    'withdrawable' => false,
                    'purchasable' => true,
                    'lot_lifetime_days' => 365,
                    'on_expiry' => 'FORFEIT',
                ],
                'lot_consumption' => 'EARLIEST_EXPIRY_FIRST', // DEC-015
                'internal_funding_full_bv' => true,           // amendments nice-to-have #6
            ],

            'calibration' => [
                'rate_bp' => 6000,
                'mode' => 'SCALE_DOWN_ONLY',
                'base' => 'PERIOD_BV',
                // Числитель месяца (amendments MF-1/2): лидерский НЕ входит (DEC-029,
                // иначе цикл), награды исключены владельцем (искл. из DEC-014).
                'include' => [
                    'structure_after_caps' => true,
                    'referral' => true,
                    'global_pool_monthly' => true,
                    'leadership' => false,
                    'awards' => false,
                ],
            ],

            'rank_forever' => true, // DEC-020
        ];
    }

    /** Три варианта квалификации GOLD+ (V1 2×anchor / V2 anchor+4×support / V3 8×support). */
    private static function variants(string $anchorRank, string $supportRank): array
    {
        return [
            'anchor_rank' => $anchorRank,
            'support_rank' => $supportRank,
            'options' => [
                ['code' => 'V1', 'anchor_count' => 2, 'support_count' => 0, 'comparator' => 'at_least', 'distinct_root_branches' => false],
                ['code' => 'V2', 'anchor_count' => 1, 'support_count' => 4, 'comparator' => 'at_least', 'distinct_root_branches' => true],
                ['code' => 'V3', 'anchor_count' => 0, 'support_count' => 8, 'comparator' => 'at_least', 'distinct_root_branches' => true],
            ],
        ];
    }
}
