<?php

namespace Modules\Calculator\Tests\Unit\V2\Policy;

use InvalidArgumentException;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * T01: negative-cases валидатора конфига политики (деньги-критичный контракт).
 * Каждый кейс мутирует канонический документ и обязан быть отвергнут.
 */
class PolicyConfigValidatorTest extends TestCase
{
    private function assertRejected(callable $mutate, string $messagePart = ''): void
    {
        $doc = DefaultPolicyConfig::doc();
        $mutate($doc);

        try {
            (new PolicyConfigValidator())->validate($doc);
            $this->fail('Ожидался InvalidArgumentException' . ($messagePart !== '' ? " ({$messagePart})" : ''));
        } catch (InvalidArgumentException $e) {
            if ($messagePart !== '') {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            } else {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUnknownTopLevelKeyRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['bonus_hack'] = ['rate' => 100];
        }, 'неизвестные ключи');
    }

    public function testMissingSectionRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            unset($doc['calibration']);
        }, 'calibration');
    }

    public function testTierGapRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['tiers'][1]['min_pv'] = 250; // дыра 200..250
        }, 'не смежны');
    }

    public function testTierOverlapRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['tiers'][1]['min_pv'] = 150; // пересечение со START (100-200)
        }, 'не смежны');
    }

    public function testNullMaxOnNonLastTierRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['tiers'][0]['max_pv_exclusive'] = null;
        });
    }

    public function testNonMonotonicSmallBranchRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            // GOLD (20000) ниже SILVER (8000).
            $doc['statuses'][5]['qualification']['small_branch_pv_min'] = 7000;
        }, 'строго возрастать');
    }

    public function testRateAbove10000BpRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][11]['binary_rate_bp'] = 10001;
        });
    }

    public function testNegativeCapRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][1]['monthly_cap_cents'] = -1;
        });
    }

    public function testFloatCapRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][1]['monthly_cap_cents'] = 50000.0; // float в money-контуре запрещён
        });
    }

    public function testHalfMonthCapMismatchRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][1]['half_month_cap_cents'] = 30000; // != 50000/2
        }, 'DEC-017');
    }

    public function testPoolSumNot300BpRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['global_pool']['pools'][0]['rate_bp'] = 125; // Σ = 325
        }, '300');
    }

    public function testMissingStatusRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            array_splice($doc['statuses'], 4, 1); // выкинуть SILVER
        }, '12');
    }

    public function testWrongStatusOrderRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            [$doc['statuses'][2], $doc['statuses'][3]] = [$doc['statuses'][3], $doc['statuses'][2]];
        });
    }

    public function testWrongOrdinalRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][5]['ordinal'] = 7;
        }, 'ordinal');
    }

    public function testEliteLeadershipRatesWrongLengthRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['leadership']['tiers']['ELITE']['rates_bp'] = [2000, 1000, 500]; // != max_depth 7
        }, 'длиной 7');
    }

    public function testAnchorRankUnknownStatusRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][7]['qualification']['variants']['anchor_rank'] = 'SUPER_DIRECTOR';
        }, 'несуществующий статус');
    }

    public function testAnchorRankNotLowerRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            // Director (7) со ссылкой на самого себя.
            $doc['statuses'][7]['qualification']['variants']['anchor_rank'] = 'DIRECTOR';
        }, 'не ниже');
    }

    public function testVariantWithoutLeadersRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][7]['qualification']['variants']['options'][0]['anchor_count'] = 0;
            $doc['statuses'][7]['qualification']['variants']['options'][0]['support_count'] = 0;
        }, 'без единого');
    }

    public function testUnknownComparatorRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][7]['qualification']['variants']['options'][0]['comparator'] = 'roughly';
        });
    }

    public function testAwardNegativeRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['award']['by_status_cents']['DIRECTOR'] = -250000;
        });
    }

    public function testVpTranchesNotThreeRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            unset($doc['award']['vp_tranches'][2]);
        }, '3 транша');
    }

    public function testCalibrationAbove100PercentRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['calibration']['rate_bp'] = 10001;
        });
    }

    public function testCalibrationLeadershipInNumeratorRejected(): void
    {
        // Amendments MF-1/2: включение лидерского в числитель = цикл расчёта.
        $this->assertRejected(static function (array &$doc) {
            $doc['calibration']['include']['leadership'] = true;
        }, 'MF-1/2');
    }

    public function testRankForeverFalseRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['rank_forever'] = false;
        }, 'DEC-020');
    }

    public function testBsWithdrawableTrueRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['accounts']['bs']['withdrawable'] = true;
        }, 'невыводим');
    }

    public function testNsTransferDayOutOfRangeRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['accounts']['ns']['transfer_days'] = [1, 31];
        });
    }

    public function testEliteDepthAboveMaxDepthRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['statuses'][11]['elite_leadership_depth'] = 7;
            $doc['leadership']['tiers']['ELITE']['max_depth'] = 6;
            $doc['leadership']['tiers']['ELITE']['rates_bp'] = [2000, 1000, 500, 300, 100, 100];
        }, 'max_depth');
    }

    public function testCurrencyNotUsdRejected(): void
    {
        $this->assertRejected(static function (array &$doc) {
            $doc['meta']['currency'] = 'KZT';
        }, 'USD');
    }

    public function testValidDocReturnedAsIs(): void
    {
        $doc = DefaultPolicyConfig::doc();
        $this->assertSame($doc, (new PolicyConfigValidator())->validate($doc));
    }
}
