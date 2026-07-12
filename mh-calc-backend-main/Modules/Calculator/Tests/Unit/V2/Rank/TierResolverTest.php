<?php

namespace Modules\Calculator\Tests\Unit\V2\Rank;

use Modules\Calculator\V2\Domain\Policy\TierRule;
use Modules\Calculator\V2\Domain\Tier\TierResolver;
use PHPUnit\Framework\TestCase;

/**
 * T05 [ДЕНЬГИ: тир -> реферальные ставки L1/L2]: резолвер тира по накопленному
 * personal PV (CAL-TIER-001). Границы спеки, накопительность, «тир не понижается».
 */
class TierResolverTest extends TestCase
{
    private TierResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new TierResolver();
    }

    /** @return TierRule[] */
    private function tiers(): array
    {
        return [
            new TierRule('START', 100, 200, 1000, 0),
            new TierRule('BUSINESS', 200, 600, 1000, 500),
            new TierRule('ELITE', 600, null, 1000, 800),
        ];
    }

    public function testBoundaries(): void
    {
        $t = $this->tiers();
        $this->assertNull($this->resolver->resolve($t, '99.999999'));
        $this->assertSame('START', $this->resolver->resolve($t, '100')?->code);
        $this->assertSame('START', $this->resolver->resolve($t, '199.999999')?->code);
        $this->assertSame('BUSINESS', $this->resolver->resolve($t, '200')?->code);
        $this->assertSame('BUSINESS', $this->resolver->resolve($t, '599.999999')?->code);
        $this->assertSame('ELITE', $this->resolver->resolve($t, '600')?->code);
    }

    public function testAccumulationCrossesTierBoundary(): void
    {
        // 90 + 180 = 270 накопленного PV => BUSINESS (по накопленному, не по одной покупке).
        $this->assertSame('BUSINESS', $this->resolver->resolve($this->tiers(), '270')?->code);
    }

    public function testOrdinalForNoDowngradeRule(): void
    {
        $t = $this->tiers();
        $this->assertSame(-1, $this->resolver->ordinal($t, null));
        $this->assertSame(0, $this->resolver->ordinal($t, 'START'));
        $this->assertSame(2, $this->resolver->ordinal($t, 'ELITE'));
        // «Тир не понижается»: ELITE(2) > BUSINESS(1) — понижения быть не должно.
        $this->assertGreaterThan(
            $this->resolver->ordinal($t, 'BUSINESS'),
            $this->resolver->ordinal($t, 'ELITE'),
        );
    }
}
