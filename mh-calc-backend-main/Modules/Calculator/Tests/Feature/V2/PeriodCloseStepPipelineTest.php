<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\V2\Support\FlakyStep;
use Modules\Calculator\Tests\Feature\V2\Support\RecordingStep;
use Modules\Calculator\V2\Domain\CalcJobExecution;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Domain\CalcSnapshot;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodCloseStepRegistry;
use Tests\TestCase;

/**
 * T04, ДЕНЬГИ: пайплайн шагов закрытия — строгий порядок order() (DEC-053),
 * фильтр supports(type); исключение в шаге = полный откат (период open,
 * постингов нет, частичного closed не существует), ретрай тем же run-рядом.
 */
class PeriodCloseStepPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingStep::$log = [];
        FlakyStep::$armed = true;
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function testStepsRunInOrderAndAreFilteredByPeriodType(): void
    {
        $this->travelTo(Carbon::parse('2026-07-17 00:10:00', 'UTC'));

        $registry = app(PeriodCloseStepRegistry::class);
        $registry->register(new RecordingStep('second', 20));
        $registry->register(new RecordingStep('first', 10));
        $registry->register(new RecordingStep('month-only', 5, CalcPeriod::TYPE_MONTH));

        app(PeriodCloseService::class)->closeHalfMonth('2026-07-H1');

        $this->assertSame(['first', 'second'], RecordingStep::$log, 'строго по order(), month-шаг отфильтрован');

        $run = CalcRun::query()->where('idempotency_key', 'close:half_month:2026-07-H1')->sole();
        $steps = collect($run->step_results);
        $this->assertSame([10, 20], $steps->pluck('order')->all());
        $this->assertSame(['first', 'second'], $steps->pluck('metrics.label')->all());
    }

    public function testFailingStepRollsBackEverythingAndRetrySucceedsWithSameRunRow(): void
    {
        $this->travelTo(Carbon::parse('2026-07-17 00:10:00', 'UTC'));

        $registry = app(PeriodCloseStepRegistry::class);
        $registry->register(new FlakyStep());
        $registry->register(new RecordingStep('after-bomb', 90));

        $closer = app(PeriodCloseService::class);

        try {
            $closer->closeHalfMonth('2026-07-H1');
            $this->fail('исключение шага обязано всплыть');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $period = CalcPeriod::query()->where('code', '2026-07-H1')->sole();
        $this->assertSame(CalcPeriod::STATUS_OPEN, $period->status, 'частичного closed не существует');
        $this->assertSame(0, CalcSnapshot::query()->count(), 'снапшот откатился вместе с транзакцией');

        $failedRun = CalcRun::query()->where('idempotency_key', 'close:half_month:2026-07-H1')->sole();
        $this->assertSame(CalcRun::STATUS_FAILED, $failedRun->status);
        $this->assertStringContainsString('boom', (string) $failedRun->error);

        $exec = CalcJobExecution::query()
            ->where('job_name', 'half-month-close')->where('window_key', '2026-07-H1')->sole();
        $this->assertSame(CalcJobExecution::STATUS_FAILED, $exec->status);

        // Ретрай после «починки» шага: тот же run-ряд (idempotency_key UNIQUE),
        // то же job-окно с attempts+1 — история не плодится.
        FlakyStep::$armed = false;
        RecordingStep::$log = [];
        $closer->closeHalfMonth('2026-07-H1');

        $this->assertSame(CalcPeriod::STATUS_CLOSED, $period->refresh()->status);
        $this->assertSame(1, CalcRun::query()->where('period_id', $period->id)->count());
        $this->assertSame(CalcRun::STATUS_SUCCEEDED, $failedRun->refresh()->status);
        $this->assertNull($failedRun->error);
        $this->assertSame(2, $exec->refresh()->attempts);
        $this->assertSame(CalcJobExecution::STATUS_SUCCEEDED, $exec->status);
        $this->assertSame(['after-bomb'], RecordingStep::$log);
        $this->assertSame(1, CalcSnapshot::query()->where('run_id', $failedRun->id)->count());
    }
}
