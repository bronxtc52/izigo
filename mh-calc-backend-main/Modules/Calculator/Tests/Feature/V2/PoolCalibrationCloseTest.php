<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2Pool;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\PoolCalibration;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Tests\TestCase;

/**
 * T11 [ДЕНЬГИ, integration]: сквозной прогон через боевой month-close пайплайн
 * (шаг PoolCalibrationCloseStep между global-allocate и finalize) + последующий перевод
 * НС→ОС (T04/T02) по закоммиченному factor_bps. Проверяет: калибровка коммитится с
 * верным фактором; reader (контракт T08/T04) его отдаёт; НС→ОС переводит floor(raw×factor)
 * на ОС, дельту (raw−paid) — на company_pool_retained (двойная запись сходится);
 * повторный перевод — no-op (идемпотентность). Флаг OFF → калибровка не считается.
 */
class PoolCalibrationCloseTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2GlobalBonus;
    use SeedsV2Pool;

    private const MONTH = '2026-07';

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function closer(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    private function closeMonthFully(string $month): void
    {
        $this->closer()->closeHalfMonth("{$month}-H1");
        $this->closer()->closeHalfMonth("{$month}-H2");
        $this->closer()->closeMonth($month);
    }

    public function testMonthCloseCommitsCalibrationAndTransferAppliesFactor(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->activateGlobalBonusPolicy();
        $this->enableFeatureFlags('mh_plan_v2_periods', 'mh_v2_pool');

        $member = $this->makeMember();
        // Числитель: структурная-после-капов 10000; база BV 10000 → factor 6000.
        $this->seedSnapshot($member, '0', 10000, CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $this->seedStructureBonus($member, self::MONTH, 10000);
        // НС кредитуется как это сделал бы posting T06 (атрибуция месяца).
        app(LedgerV2::class)->credit(
            $member, LedgerV2::SUBACCOUNT_NS, 10000, "seed:ns:{$member}:" . self::MONTH,
            null, 'structure_bonus', null, self::MONTH,
        );

        // --- Закрытие месяца через боевой пайплайн (шаг калибровки T11) ---
        $this->closeMonthFully(self::MONTH);

        $cal = PoolCalibration::query()->where('month', self::MONTH)
            ->where('status', PoolCalibration::STATUS_COMMITTED)->sole();
        $this->assertSame(6000, $cal->factor_bps);
        $this->assertSame(10000, $cal->total_after_caps_cents);
        $this->assertSame(6000, $cal->scaled_total_cents);
        $this->assertSame(4000, $cal->company_retained_cents);

        // Контракт T08/T04: reader отдаёт закоммиченный factor_bps.
        $this->assertSame(6000, app(PoolCalibrationReader::class)->factorBpsFor(self::MONTH));

        // --- Перевод НС→ОС по фактору (T04 команда → T02 сервис) ---
        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $os = (int) LedgerEntry::query()->where('member_id', $member)
            ->where('account_type', LedgerPostingV2Service::ACC_OS_AVAILABLE)
            ->where('direction', LedgerPostingV2Service::CR)->sum('amount_cents');
        $retained = (int) LedgerEntry::query()
            ->where('account_type', LedgerPostingV2Service::ACC_POOL_RETAINED)
            ->where('direction', LedgerPostingV2Service::CR)->sum('amount_cents');

        $this->assertSame(6000, $os);       // floor(10000 × 0.6)
        $this->assertSame(4000, $retained); // дельта калибровки удержана компанией

        // Двойная запись сходится глобально.
        $debit = (int) LedgerEntry::query()->where('direction', 'debit')->sum('amount_cents');
        $credit = (int) LedgerEntry::query()->where('direction', 'credit')->sum('amount_cents');
        $this->assertSame($debit, $credit);

        // Идемпотентность: повторный перевод — no-op.
        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);
        $this->assertSame(6000, (int) LedgerEntry::query()->where('member_id', $member)
            ->where('account_type', LedgerPostingV2Service::ACC_OS_AVAILABLE)
            ->where('direction', LedgerPostingV2Service::CR)->sum('amount_cents'));
    }

    public function testFlagOffSkipsCalibration(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->activateGlobalBonusPolicy();
        $this->enableFeatureFlags('mh_plan_v2_periods'); // mh_v2_pool OFF

        $member = $this->makeMember();
        $this->seedSnapshot($member, '0', 10000, CarbonImmutable::parse('2026-07-10 12:00:00', 'UTC'));
        $this->seedStructureBonus($member, self::MONTH, 10000);

        $this->closeMonthFully(self::MONTH);

        // Калибровка не считалась; reader fail-closed (null) → перевод НС→ОС не выполнится.
        $this->assertSame(0, PoolCalibration::query()->count());
        $this->assertNull(app(PoolCalibrationReader::class)->factorBpsFor(self::MONTH));
        $month = CalcPeriod::query()->where('code', self::MONTH)->sole();
        $this->assertSame(CalcPeriod::STATUS_CLOSED, $month->status); // месяц всё равно закрыт
    }
}
