<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Tests\TestCase;

/**
 * T09 [идемпотентность]: команда ручного пересчёта calculator:v2:global-allocate.
 * Флаг OFF → no-op; draft → детерминированный пересчёт; final → no-op.
 */
class GlobalBonusAllocateCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';

    private function seedDirector(): void
    {
        $buyer = $this->makeMember();
        $this->seedSnapshot($buyer, '0.000000', 1_000_000, CarbonImmutable::parse('2026-03-10', 'UTC'));
        $d = $this->makeMember();
        $this->seedRank($d, StatusCode::DIRECTOR);
        $this->seedSnapshot($d, '100000.000000', 0, CarbonImmutable::parse('2026-03-10', 'UTC'));
    }

    public function testFlagOffIsNoop(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);
        $this->seedDirector();

        $this->assertSame(0, Artisan::call('calculator:v2:global-allocate', ['month' => self::MONTH]));
        $this->assertSame(0, GlobalBonusMonth::query()->count());
    }

    public function testDraftRecomputeThenFinalNoop(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableGlobalBonusFlag();
        $period = $this->ensurePeriod(self::MONTH);
        $this->seedDirector();

        Artisan::call('calculator:v2:global-allocate', ['month' => self::MONTH]);
        $month = GlobalBonusMonth::query()->firstOrFail();
        $this->assertSame(GlobalBonusMonth::STATUS_DRAFT, $month->status);
        $bv1 = (int) $month->global_bv_cents;

        // Повторный прогон draft → те же суммы (детерминизм).
        Artisan::call('calculator:v2:global-allocate', ['month' => self::MONTH]);
        $this->assertSame($bv1, (int) GlobalBonusMonth::query()->firstOrFail()->global_bv_cents);

        // Финализируем → команда становится no-op (месяц остаётся final).
        app(\Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService::class)->finalizeMonth($period);
        Artisan::call('calculator:v2:global-allocate', ['month' => self::MONTH]);
        $this->assertSame(GlobalBonusMonth::STATUS_FINAL, GlobalBonusMonth::query()->firstOrFail()->status);
    }

    public function testUnknownMonthFails(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableGlobalBonusFlag();

        // Период не создан → команда падает с понятным кодом.
        $this->assertSame(1, Artisan::call('calculator:v2:global-allocate', ['month' => '2099-01']));
    }
}
