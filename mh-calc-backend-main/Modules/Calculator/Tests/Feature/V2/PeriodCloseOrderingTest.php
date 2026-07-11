<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\FakePoolCalibrationReader;
use Modules\Calculator\Tests\Feature\V2\Support\SpyNsToOsTransfer;
use Modules\Calculator\Tests\Feature\V2\Support\SpyQuarterPayoutHandler;
use Modules\Calculator\V2\Contracts\NsToOsTransfer;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Contracts\QuarterGlobalPayoutHandler;
use Modules\Calculator\V2\Domain\CalcJobExecution;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Tests\TestCase;

/**
 * T04, ДЕНЬГИ: порядок закрытий — месяц только после обоих half-month; квартал
 * только после 3 закрытых месяцев; перевод НС→ОС только из закрытого И
 * откалиброванного месяца (MF-4: FINAL → CALIBRATE → TRANSFER); catch-up после
 * простоя планировщика.
 */
class PeriodCloseOrderingTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFeatureFlags('mh_plan_v2_periods');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function closer(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    /** Закрыть месяц целиком сервисом (halves + month) — фикстура для сценариев выше. */
    private function closeMonthFully(string $month): void
    {
        $this->closer()->closeHalfMonth("{$month}-H1");
        $this->closer()->closeHalfMonth("{$month}-H2");
        $this->closer()->closeMonth($month);
    }

    public function testMonthCloseBlockedWhileHalfMonthOpen(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1'); // H2 остаётся open

        $this->artisan('calc-v2:month-close')->assertExitCode(0);

        $month = CalcPeriod::query()->where('code', '2026-07')->sole();
        $this->assertSame(CalcPeriod::STATUS_OPEN, $month->status, 'месяц НЕ закрыт при открытом H2');

        $exec = CalcJobExecution::query()
            ->where('job_name', 'month-close')->where('window_key', '2026-07')->sole();
        $this->assertSame(CalcJobExecution::STATUS_FAILED, $exec->status);
        $this->assertStringContainsString('BLOCKED', (string) $exec->error);

        $this->assertSame(0, CalcRun::query()->where('period_id', $month->id)->count(), 'шаги не вызывались, run не создан');

        // После закрытия H2 — ретрай тем же окном (attempts+1) закрывает месяц.
        $this->closer()->closeHalfMonth('2026-07-H2');
        $this->artisan('calc-v2:month-close')->assertExitCode(0);

        $this->assertSame(CalcPeriod::STATUS_CLOSED, $month->refresh()->status);
        $exec->refresh();
        $this->assertSame(CalcJobExecution::STATUS_SUCCEEDED, $exec->status);
        $this->assertSame(2, $exec->attempts);
    }

    /** MF-4: перевод не выполняется, пока месяц не откалиброван (Null-reader T04). */
    public function testTransferWaitsForCalibration(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->closeMonthFully('2026-07');

        $spy = new SpyNsToOsTransfer();
        $this->app->instance(NsToOsTransfer::class, $spy);
        // PoolCalibrationReader НЕ подменяем: дефолт = Null (месяц не откалиброван).

        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $this->assertSame([], $spy->calls);
        $this->assertSame(0, CalcJobExecution::query()->where('job_name', 'ns-os-transfer')->count(), 'окно даже не занимается — ждём T11-калибровку');
    }

    /** MF-4: перевод не выполняется из НЕзакрытого месяца, даже если калибровка есть. */
    public function testTransferWaitsForMonthClose(): void
    {
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $this->closer()->closeHalfMonth('2026-07-H1'); // месяц остаётся open

        $spy = new SpyNsToOsTransfer();
        $this->app->instance(NsToOsTransfer::class, $spy);
        $this->app->instance(PoolCalibrationReader::class, new FakePoolCalibrationReader(['2026-07' => 10000]));

        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $this->assertSame([], $spy->calls, 'порядок FINAL→TRANSFER: сначала закрытие месяца');
    }

    public function testQuarterPayoutRequiresThreeClosedMonths(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 00:40:00', 'UTC'));

        $spy = new SpyQuarterPayoutHandler();
        $this->app->instance(QuarterGlobalPayoutHandler::class, $spy);

        // 2 из 3 месяцев закрыты — payout BLOCKED, квартал остаётся open.
        $this->closeMonthFully('2026-07');
        $this->closeMonthFully('2026-08');

        $this->artisan('calc-v2:quarter-payout')->assertExitCode(0);

        $quarter = CalcPeriod::query()->where('code', '2026-Q3')->sole();
        $this->assertSame(CalcPeriod::STATUS_OPEN, $quarter->status);
        $this->assertSame([], $spy->calls, 'handler НЕ вызван при 2/3 месяцах');
        $exec = CalcJobExecution::query()
            ->where('job_name', 'quarter-payout')->where('window_key', '2026-Q3')->sole();
        $this->assertSame(CalcJobExecution::STATUS_FAILED, $exec->status);
        $this->assertStringContainsString('BLOCKED', (string) $exec->error);

        // Третий месяц закрыт — payout выполняется ровно один раз.
        $this->closeMonthFully('2026-09');
        $this->artisan('calc-v2:quarter-payout')->assertExitCode(0);
        $this->artisan('calc-v2:quarter-payout')->assertExitCode(0);

        $this->assertSame(CalcPeriod::STATUS_CLOSED, $quarter->refresh()->status);
        $this->assertCount(1, $spy->calls, 'ровно одна квартальная выплата на окно');
        $this->assertSame('2026-Q3', $spy->calls[0]['quarter']);
        $this->assertSame('2026-Q3', $spy->calls[0]['window']);
        $this->assertCount(3, $spy->calls[0]['month_ids']);

        $run = CalcRun::query()->where('idempotency_key', 'close:quarter:2026-Q3')->sole();
        $this->assertSame(CalcRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(
            'quarter_global_payout',
            collect($run->step_results)->last()['step'] ?? null,
            'метрика выплаты в step_results'
        );
    }

    /** Планировщик «проспал» границу 16-го: запуск 18-го закрывает H1 одним окном. */
    public function testCatchUpClosesOverdueHalfMonth(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 00:01:00', 'UTC'));
        $this->artisan('calc-v2:periods-ensure')->assertExitCode(0);
        $this->assertSame(
            CalcPeriod::STATUS_OPEN,
            CalcPeriod::query()->where('code', '2026-07-H1')->sole()->status
        );

        // Простой до 18-го — тик закрывает просроченный H1 (catch-up), одним окном.
        $this->travelTo(Carbon::parse('2026-07-18 00:10:00', 'UTC'));
        $this->artisan('calc-v2:half-month-close')->assertExitCode(0);

        $this->assertSame(
            CalcPeriod::STATUS_CLOSED,
            CalcPeriod::query()->where('code', '2026-07-H1')->sole()->status
        );
        $this->assertSame(
            1,
            CalcJobExecution::query()->where('job_name', 'half-month-close')->where('window_key', '2026-07-H1')->count(),
            'одно окно H1, не два'
        );
        // Текущий H2 не тронут.
        $this->assertSame(
            CalcPeriod::STATUS_OPEN,
            CalcPeriod::query()->where('code', '2026-07-H2')->sole()->status
        );
    }
}
