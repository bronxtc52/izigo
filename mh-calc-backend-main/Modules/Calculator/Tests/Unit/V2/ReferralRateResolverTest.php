<?php

namespace Modules\Calculator\Tests\Unit\V2;

use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Domain\Policy\TierRule;
use Modules\Calculator\V2\Services\Referral\ReferralRateResolver;
use PHPUnit\Framework\TestCase;

/**
 * T07 GOLDEN (CAL-REF-001): матрица реферальных ставок {тир}×{глубина} в bps.
 * L1 = 10% для всех тиров; L2 = START 0% / BUSINESS 5% / ELITE 8% (BR-TIER-001,
 * 02_Business_Rules §8.2). Тир null (ниже START) → 0. Неизвестный тир / глубина
 * вне 1..2 → fail-fast. Ставки — из тира ПОЛУЧАТЕЛЯ (TierRule, T01).
 */
class ReferralRateResolverTest extends TestCase
{
    private function policy(): PolicyV2
    {
        return new class extends PolicyV2 {
            public function __construct()
            {
                // provenance/секции не нужны — резолвер трогает только tierByCode().
            }

            public function tierByCode(string $code): TierRule
            {
                return match ($code) {
                    'START' => new TierRule('START', 100, 200, 1000, 0),
                    'BUSINESS' => new TierRule('BUSINESS', 200, 600, 1000, 500),
                    'ELITE' => new TierRule('ELITE', 600, null, 1000, 800),
                    default => throw new \InvalidArgumentException("Неизвестный тир: {$code}"),
                };
            }
        };
    }

    public function testL1IsTenPercentForEveryTier(): void
    {
        $r = new ReferralRateResolver();
        $p = $this->policy();
        $this->assertSame(1000, $r->rateBps($p, 'START', 1));
        $this->assertSame(1000, $r->rateBps($p, 'BUSINESS', 1));
        $this->assertSame(1000, $r->rateBps($p, 'ELITE', 1));
    }

    public function testL2RatesByTier(): void
    {
        $r = new ReferralRateResolver();
        $p = $this->policy();
        $this->assertSame(0, $r->rateBps($p, 'START', 2));       // START L2 = 0%
        $this->assertSame(500, $r->rateBps($p, 'BUSINESS', 2));  // BUSINESS L2 = 5%
        $this->assertSame(800, $r->rateBps($p, 'ELITE', 2));     // ELITE L2 = 8%
    }

    public function testNullTierPaysNothingAtEitherDepth(): void
    {
        $r = new ReferralRateResolver();
        $p = $this->policy();
        // Ниже START — тира нет, реферальная не начисляется.
        $this->assertSame(0, $r->rateBps($p, null, 1));
        $this->assertSame(0, $r->rateBps($p, null, 2));
    }

    public function testUnknownTierFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReferralRateResolver())->rateBps($this->policy(), 'PLATINUM', 1);
    }

    public function testDepthOutOfRangeFailsFast(): void
    {
        $this->expectException(\DomainException::class);
        (new ReferralRateResolver())->rateBps($this->policy(), 'ELITE', 3);
    }

    public function testDepthZeroFailsFast(): void
    {
        $this->expectException(\DomainException::class);
        (new ReferralRateResolver())->rateBps($this->policy(), 'ELITE', 0);
    }
}
