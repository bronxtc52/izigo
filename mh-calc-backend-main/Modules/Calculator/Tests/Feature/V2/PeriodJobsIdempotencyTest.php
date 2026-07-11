<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\FakePoolCalibrationReader;
use Modules\Calculator\Tests\Feature\V2\Support\SpyNsToOsTransfer;
use Modules\Calculator\V2\Contracts\NsToOsTransfer;
use Modules\Calculator\V2\Contracts\PoolCalibrationReader;
use Modules\Calculator\V2\Domain\CalcJobExecution;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Periods\JobExecutionGuard;
use Tests\TestCase;

/**
 * T04, ДЕНЬГИ (обязательный по тест-плану): идемпотентность scheduled-джобов по окну.
 * Повтор команды не создаёт второй run/перевод; конкурентное окно = корректный no-op.
 */
class PeriodJobsIdempotencyTest extends TestCase
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

    public function testHalfMonthCloseCommandTwiceProducesSingleRunAndExecution(): void
    {
        $this->travelTo(Carbon::parse('2026-07-17 00:10:00', 'UTC'));

        $this->artisan('calc-v2:half-month-close')->assertExitCode(0);
        $this->artisan('calc-v2:half-month-close')->assertExitCode(0);

        $period = CalcPeriod::query()->where('code', '2026-07-H1')->sole();
        $this->assertSame(CalcPeriod::STATUS_CLOSED, $period->status);

        $this->assertSame(
            1,
            CalcRun::query()->where('idempotency_key', 'close:half_month:2026-07-H1')->count(),
            'второй запуск — no-op, run не задваивается'
        );
        $this->assertSame(
            1,
            CalcJobExecution::query()->where('job_name', 'half-month-close')->where('window_key', '2026-07-H1')->count()
        );
        $exec = CalcJobExecution::query()->where('window_key', '2026-07-H1')->sole();
        $this->assertSame(CalcJobExecution::STATUS_SUCCEEDED, $exec->status);
        $this->assertSame(1, $exec->attempts);
    }

    /**
     * Реалистичный жизненный цикл планировщика: периоды заведены в июле
     * (periods-ensure), 1 августа тики закрывают оба half-month и месяц.
     */
    private function closeJulyViaCommands(): void
    {
        $this->travelTo(Carbon::parse('2026-07-05 00:01:00', 'UTC'));
        $this->artisan('calc-v2:periods-ensure')->assertExitCode(0);

        $this->travelTo(Carbon::parse('2026-08-01 00:35:00', 'UTC'));
        $this->artisan('calc-v2:half-month-close')->assertExitCode(0);
        $this->artisan('calc-v2:month-close')->assertExitCode(0);

        $this->assertSame(
            CalcPeriod::STATUS_CLOSED,
            CalcPeriod::query()->where('code', '2026-07')->sole()->status
        );
    }

    public function testNsOsTransferHandlerCalledExactlyOncePerCalibratedMonth(): void
    {
        $this->closeJulyViaCommands();

        // Гейт MF-4: месяц закрыт И откалиброван (fake T11), операция — spy T02.
        $spy = new SpyNsToOsTransfer();
        $this->app->instance(NsToOsTransfer::class, $spy);
        $this->app->instance(PoolCalibrationReader::class, new FakePoolCalibrationReader(['2026-07' => 8000]));

        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);
        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $this->assertSame([['2026-07', 8000]], $spy->calls, 'ровно один вызов на окно месяца');

        $exec = CalcJobExecution::query()
            ->where('job_name', 'ns-os-transfer')->where('window_key', 'ns-os:2026-07')->sole();
        $this->assertSame(CalcJobExecution::STATUS_SUCCEEDED, $exec->status);
    }

    public function testConcurrentWindowClaimYieldsNoOpNotException(): void
    {
        $this->closeJulyViaCommands();

        $spy = new SpyNsToOsTransfer();
        $this->app->instance(NsToOsTransfer::class, $spy);
        $this->app->instance(PoolCalibrationReader::class, new FakePoolCalibrationReader(['2026-07' => 10000]));

        // «Конкурент» уже держит окно (running, свежий lease) — команда обязана
        // выйти no-op без исключения и без второго ряда.
        $held = app(JobExecutionGuard::class)->claim('ns-os-transfer', 'ns-os:2026-07');
        $this->assertNotNull($held);

        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $this->assertSame([], $spy->calls, 'живое чужое окно — перевод не выполняется');
        $this->assertSame(1, CalcJobExecution::query()->where('window_key', 'ns-os:2026-07')->count());

        // Повторный claim того же окна из «нашего» процесса — тоже null (unique + lease).
        $this->assertNull(app(JobExecutionGuard::class)->claim('ns-os-transfer', 'ns-os:2026-07'));
    }

    public function testSucceededWindowIsNeverReexecuted(): void
    {
        $this->closeJulyViaCommands();

        $guard = app(JobExecutionGuard::class);
        $exec = $guard->claim('ns-os-transfer', 'ns-os:2026-07');
        $guard->succeed($exec);

        $spy = new SpyNsToOsTransfer();
        $this->app->instance(NsToOsTransfer::class, $spy);
        $this->app->instance(PoolCalibrationReader::class, new FakePoolCalibrationReader(['2026-07' => 9000]));

        $this->artisan('calc-v2:ns-os-transfer')->assertExitCode(0);

        $this->assertSame([], $spy->calls, 'succeeded-окно = вечный no-op (деньги не задваиваются)');
    }
}
