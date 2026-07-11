<?php

namespace Modules\Calculator\Tests\Unit\V2\Policy;

use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * T01 GOLDEN-ТЕСТ ДЕНЕГ: канонический конфиг политики MH V2 — точные значения в
 * USD-центах по курсу 468 KZT = 1 USD (сверка с 07_Rules_Config.example.yaml и
 * планом Гейта A). Любое изменение этих чисел = изменение денег партнёров —
 * тест обязан упасть и потребовать осознанной правки.
 */
class DefaultPolicyConfigTest extends TestCase
{
    private static function doc(): array
    {
        return DefaultPolicyConfig::doc();
    }

    public function testPassesValidator(): void
    {
        $validated = (new PolicyConfigValidator())->validate(self::doc());
        $this->assertSame(self::doc(), $validated);
    }

    public function testTwelveStatusesInCanonicalOrder(): void
    {
        $codes = array_column(self::doc()['statuses'], 'code');
        $this->assertSame([
            'CLIENT', 'CONSULTANT', 'MANAGER', 'BRONZE_MANAGER', 'SILVER_MANAGER',
            'GOLD_MANAGER', 'PLATINUM_MANAGER', 'DIRECTOR', 'PEARL_DIRECTOR',
            'SAPPHIRE_DIRECTOR', 'DIAMOND_DIRECTOR', 'VICE_PRESIDENT',
        ], $codes);
        $this->assertSame(range(0, 11), array_column(self::doc()['statuses'], 'ordinal'));
    }

    /** Капы: USD 500/1000/1500/2000/5000/10000/15000/25000/30000/35000/40000 в центах. */
    public function testMonthlyCapsExactCents(): void
    {
        $caps = array_column(self::doc()['statuses'], 'monthly_cap_cents');
        $this->assertSame([
            0, 50000, 100000, 150000, 200000, 500000, 1000000,
            1500000, 2500000, 3000000, 3500000, 4000000,
        ], $caps);

        // DEC-017: полумесячный кап хранится явно и равен monthly/2.
        foreach (self::doc()['statuses'] as $status) {
            $this->assertSame(
                intdiv($status['monthly_cap_cents'], 2),
                $status['half_month_cap_cents'],
                $status['code'],
            );
        }
    }

    public function testBinaryRatesBp(): void
    {
        $rates = array_column(self::doc()['statuses'], 'binary_rate_bp');
        $this->assertSame([0, 500, 500, 500, 500, 600, 600, 700, 700, 800, 800, 900], $rates);
    }

    public function testSmallBranchThresholds(): void
    {
        $thresholds = [];
        foreach (self::doc()['statuses'] as $status) {
            if (isset($status['qualification']['small_branch_pv_min'])) {
                $thresholds[] = $status['qualification']['small_branch_pv_min'];
            }
        }
        $this->assertSame([
            1000, 3000, 8000, 20000, 60000, 150000, 380000, 760000, 1500000, 3000000,
        ], $thresholds);
    }

    /** Награды: 100/200/300/500/1500/2500/20000/35000/53000 USD + VP 3x50000 в центах. */
    public function testAwardsExactCents(): void
    {
        $award = self::doc()['award'];
        $this->assertSame([
            'MANAGER' => 10000,
            'BRONZE_MANAGER' => 20000,
            'SILVER_MANAGER' => 30000,
            'GOLD_MANAGER' => 50000,
            'PLATINUM_MANAGER' => 150000,
            'DIRECTOR' => 250000,
            'PEARL_DIRECTOR' => 2000000,
            'SAPPHIRE_DIRECTOR' => 3500000,
            'DIAMOND_DIRECTOR' => 5300000,
        ], $award['by_status_cents']);

        $this->assertSame('BS', $award['destination']);
        $this->assertSame('ALL_CROSSED', $award['on_rank_jump']);
        $this->assertCount(3, $award['vp_tranches']);
        foreach ($award['vp_tranches'] as $tranche) {
            $this->assertSame(5000000, $tranche['amount_cents']);
        }
        $this->assertSame(
            ['STATUS_ACHIEVED', 'FIRST_VP_GLOBAL_BONUS_QUALIFICATION', 'SECOND_VP_GLOBAL_BONUS_QUALIFICATION'],
            array_column($award['vp_tranches'], 'trigger'),
        );
    }

    public function testGlobalPools(): void
    {
        $pool = self::doc()['global_pool'];
        $this->assertSame([100, 75, 50, 50, 25], array_column($pool['pools'], 'rate_bp'));
        $this->assertSame(300, array_sum(array_column($pool['pools'], 'rate_bp')));
        $this->assertSame(
            [100000, 400000, 1000000, 3000000, 6000000],
            array_column($pool['pools'], 'one_share_pv_min'),
        );
        $this->assertSame(2, $pool['max_shares']);         // DEC-032
        $this->assertSame(2500, $pool['member_cap_bp']);   // кап 25% пула
        $this->assertSame('COMPANY_UNALLOCATED', $pool['remainder']); // DEC-034
        $this->assertTrue($pool['inherits_lower_pools']);
    }

    public function testReferralRatesByTier(): void
    {
        $tiers = self::doc()['tiers'];
        $this->assertSame(['START', 'BUSINESS', 'ELITE'], array_column($tiers, 'code'));
        $this->assertSame([100, 200, 600], array_column($tiers, 'min_pv'));
        $this->assertSame([200, 600, null], array_column($tiers, 'max_pv_exclusive'));
        foreach ($tiers as $tier) {
            $this->assertSame(1000, $tier['referral_rates_bp']['l1'], $tier['code']);
        }
        $this->assertSame([0, 500, 800], array_map(
            static fn (array $t) => $t['referral_rates_bp']['l2'],
            $tiers,
        ));

        // Решение владельца 2026-07-12: реферальная платится всегда.
        $this->assertFalse(self::doc()['referral']['stop_at_elite']);
    }

    public function testLeadership(): void
    {
        $leadership = self::doc()['leadership'];
        $this->assertSame([1000], $leadership['tiers']['START']['rates_bp']);
        $this->assertSame([1500], $leadership['tiers']['BUSINESS']['rates_bp']);
        $this->assertSame([2000, 1000, 500, 300, 100, 100, 100], $leadership['tiers']['ELITE']['rates_bp']);
        $this->assertCount(7, $leadership['tiers']['ELITE']['rates_bp']);
        $this->assertSame('PAID_AFTER_CAPS_AND_POOL', $leadership['base']); // DEC-029
        $this->assertSame(3, $leadership['rank_gap_block_ordinal_diff']);   // DEC-030

        // Глубины ELITE по статусу: 0/0/1/1/2/3/4/5/6/7/7/7.
        $this->assertSame(
            [0, 0, 1, 1, 2, 3, 4, 5, 6, 7, 7, 7],
            array_column(self::doc()['statuses'], 'elite_leadership_depth'),
        );
    }

    public function testCalibrationPerAmendments(): void
    {
        $calibration = self::doc()['calibration'];
        $this->assertSame(6000, $calibration['rate_bp']);
        $this->assertSame('SCALE_DOWN_ONLY', $calibration['mode']);
        // MF-1/2: лидерский НЕ в числителе (DEC-029), награды исключены владельцем.
        $this->assertFalse($calibration['include']['leadership']);
        $this->assertFalse($calibration['include']['awards']);
        $this->assertTrue($calibration['include']['structure_after_caps']);
        $this->assertTrue($calibration['include']['referral']);
        $this->assertTrue($calibration['include']['global_pool_monthly']);
    }

    public function testAccounts(): void
    {
        $accounts = self::doc()['accounts'];
        $this->assertSame(7000, $accounts['os']['max_order_payment_share_bp']); // ОС <= 70%
        $this->assertSame(365, $accounts['os']['lot_lifetime_days']);
        $this->assertSame('TRANSFER_TO_BS', $accounts['os']['on_expiry']);
        $this->assertSame([1, 16], $accounts['ns']['transfer_days']);
        $this->assertFalse($accounts['bs']['withdrawable']);
        $this->assertSame(365, $accounts['bs']['lot_lifetime_days']);
        $this->assertSame('EARLIEST_EXPIRY_FIRST', $accounts['lot_consumption']); // DEC-015
        $this->assertTrue($accounts['internal_funding_full_bv']);
    }

    public function testMeta(): void
    {
        $this->assertSame(
            ['currency' => 'USD', 'kzt_rate' => 468, 'timezone' => 'UTC'],
            self::doc()['meta'],
        );
        $this->assertTrue(self::doc()['rank_forever']); // DEC-020
        // Решения владельца: подписки и скидки MH в конфиге НЕТ вовсе.
        $this->assertArrayNotHasKey('subscription', self::doc());
        $this->assertArrayNotHasKey('mh_discount', self::doc());
    }

    /** Hash канонический: перестановка ключей не меняет sha256. */
    public function testCanonicalHashStable(): void
    {
        $doc = self::doc();
        $shuffled = $doc;
        // Переставляем ключи верхнего уровня и внутри meta.
        $shuffled = array_reverse($shuffled, true);
        $shuffled['meta'] = array_reverse($shuffled['meta'], true);

        $this->assertSame(
            DefaultPolicyConfig::canonicalHash($doc),
            DefaultPolicyConfig::canonicalHash($shuffled),
        );
        $this->assertNotSame(
            DefaultPolicyConfig::canonicalHash($doc),
            DefaultPolicyConfig::canonicalHash(array_replace($doc, ['rank_forever' => false])),
        );
    }

    /** Все деньги/ставки/PV-пороги — строго integer (DEC-002: float в money-контуре запрещён). */
    public function testNoFloatsAnywhere(): void
    {
        $this->assertNoFloat(self::doc(), 'config');
    }

    private function assertNoFloat(array $value, string $path): void
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $this->assertNoFloat($v, "{$path}.{$k}");
            } else {
                $this->assertIsNotFloat($v, "{$path}.{$k} содержит float");
            }
        }
    }
}
