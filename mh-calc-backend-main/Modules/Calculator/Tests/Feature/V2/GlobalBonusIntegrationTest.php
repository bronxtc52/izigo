<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Tests\TestCase;

/**
 * T09 [integration]: сквозной прогон через боевой пайплайн закрытия — закрытие
 * half-month → month (шаги allocate+finalize T09) для 3 месяцев → закрытие квартала
 * (handler квартальной выплаты). Проверяет: месяцы становятся final; ОС участника =
 * Σ final_cents; двойная запись ledger сходится (Σdebit == Σcredit).
 */
class GlobalBonusIntegrationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsV2GlobalBonus;

    public function testEndToEndMonthlyAllocationThenQuarterlyPayout(): void
    {
        $this->activateGlobalBonusPolicy();
        $this->enableGlobalBonusFlag();
        $closer = app(PeriodCloseService::class);

        // Один Director с личным PV (1 доля) и BV-объёмом в каждом из 3 месяцев Q1.
        $director = $this->makeMember();
        $this->seedRank($director, StatusCode::DIRECTOR, CarbonImmutable::parse('2025-12-01', 'UTC'));

        $months = ['2026-01', '2026-02', '2026-03'];
        foreach ($months as $code) {
            $paidAt = CarbonImmutable::parse("{$code}-10 12:00:00", 'UTC');
            $this->seedSnapshot($director, '100000.000000', 1_000_000, $paidAt);
        }
        $this->ensurePeriod('2026-Q1');

        // Закрываем half-month'ы и месяцы (шаги T09 отрабатывают в month-close).
        foreach ($months as $code) {
            $closer->closeHalfMonth("{$code}-H1");
            $closer->closeHalfMonth("{$code}-H2");
            $closer->closeMonth($code);
        }

        // Все 3 месяца глобального бонуса финализированы.
        $this->assertSame(3, GlobalBonusMonth::query()->where('status', GlobalBonusMonth::STATUS_FINAL)->count());

        // Закрываем квартал → квартальная выплата на ОС.
        $closer->closeQuarter('2026-Q1');

        // ОС участника == Σ final_cents его member-аллокаций за квартал.
        $expected = (int) GlobalBonusAllocation::query()
            ->where('member_id', $director)
            ->where('kind', GlobalBonusAllocation::KIND_MEMBER)
            ->sum('final_cents');
        $this->assertGreaterThan(0, $expected);
        $this->assertSame($expected, (int) MemberAccountV2::query()->where('member_id', $director)->value('os_available_cents'));

        // Двойная запись сходится глобально.
        $debit = (int) LedgerEntry::query()->where('direction', 'debit')->sum('amount_cents');
        $credit = (int) LedgerEntry::query()->where('direction', 'credit')->sum('amount_cents');
        $this->assertSame($debit, $credit);
        $this->assertGreaterThan(0, $credit);
    }
}
