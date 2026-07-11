<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\V2\Support\FakePolicy;
use Modules\Calculator\Tests\Feature\V2\Support\FakePolicyResolver;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\CalcJobExecution;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Domain\CalcRun;
use Modules\Calculator\V2\Services\Periods\ClosedPeriodException;
use Modules\Calculator\V2\Services\Periods\PeriodCloseBlockedException;
use Modules\Calculator\V2\Services\Periods\PeriodCloseService;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Tests\TestCase;

/**
 * T04: жизненный цикл периодов — идемпотентное создание, резолв политики на
 * starts_at (контракт T01), переходы open→closing→closed и guard
 * «закрытый период неизменяем» (ДЕНЬГИ).
 */
class PeriodLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function periods(): PeriodService
    {
        return app(PeriodService::class);
    }

    private function closer(): PeriodCloseService
    {
        return app(PeriodCloseService::class);
    }

    public function testEnsureIsIdempotent(): void
    {
        $first = $this->periods()->ensureByCode('2026-07-H1');
        $second = $this->periods()->ensureByCode('2026-07-H1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CalcPeriod::query()->where('code', '2026-07-H1')->count());
        $this->assertSame(CalcPeriod::STATUS_OPEN, $second->status);
        $this->assertSame('2026-07-01 00:00:00', $second->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 00:00:00', $second->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $second->timezone);
    }

    public function testEnsureCalendarIsIdempotentAndCoversAllTypes(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20 10:00:00', 'UTC'));

        $created = $this->periods()->ensureCalendar(now());
        $again = $this->periods()->ensureCalendar(now());

        $this->assertGreaterThan(0, $created);
        $this->assertSame(0, $again, 'повторный прогон не создаёт дублей');

        foreach ([
            [CalcPeriod::TYPE_HALF_MONTH, ['2026-07-H1', '2026-07-H2', '2026-08-H1']],
            [CalcPeriod::TYPE_MONTH, ['2026-06', '2026-07', '2026-08']],
            [CalcPeriod::TYPE_QUARTER, ['2026-Q2', '2026-Q3', '2026-Q4']],
        ] as [$type, $codes]) {
            foreach ($codes as $code) {
                $this->assertDatabaseHas('v2_calc_periods', ['period_type' => $type, 'code' => $code]);
            }
        }
    }

    public function testPolicyVersionResolvedOnStartsAtViaT01Contract(): void
    {
        $this->app->instance(PolicyVersionResolver::class, new FakePolicyResolver(new FakePolicy(id: 77)));

        $period = $this->periods()->ensureByCode('2026-07-H1');

        $this->assertSame(77, $period->policy_version_id);
    }

    public function testPolicyVersionNullUntilResolverBound(): void
    {
        $period = $this->periods()->ensureByCode('2026-07-H1');

        $this->assertNull($period->policy_version_id, 'до merge T01 резолвер не забинден — период без привязки');
    }

    /** ДЕНЬГИ: guard обязателен — постинги в закрытый период запрещены. */
    public function testAssertOpenThrowsOnClosedPeriod(): void
    {
        $period = $this->periods()->ensureByCode('2026-07-H1');
        $this->periods()->assertOpen($period); // open — проходит

        $period->update(['status' => CalcPeriod::STATUS_CLOSING]);
        $this->periods()->assertOpen($period->refresh(), allowClosing: true); // пайплайн закрытия

        try {
            $this->periods()->assertOpen($period->refresh());
            $this->fail('closing без allowClosing должен падать');
        } catch (ClosedPeriodException) {
        }

        $period->update(['status' => CalcPeriod::STATUS_CLOSED]);
        $this->expectException(ClosedPeriodException::class);
        $this->periods()->assertOpen($period->refresh(), allowClosing: true);
    }

    public function testCloseHalfMonthTransitionsAndIsIdempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-07-17 00:10:00', 'UTC'));

        $this->closer()->closeHalfMonth('2026-07-H1');

        $period = $this->periods()->findByCode('2026-07-H1');
        $this->assertSame(CalcPeriod::STATUS_CLOSED, $period->status);
        $this->assertSame('system', $period->closed_by);
        $this->assertNotNull($period->closed_at);

        $run = CalcRun::query()->where('idempotency_key', 'close:half_month:2026-07-H1')->sole();
        $this->assertSame(CalcRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame(CalcRun::MODE_CLOSE, $run->mode);
        $this->assertNotNull($run->result_hash);
        $this->assertSame('2026-07-16 00:00:00', $run->input_cutoff->format('Y-m-d H:i:s'));

        $exec = CalcJobExecution::query()
            ->where('job_name', 'half-month-close')->where('window_key', '2026-07-H1')->sole();
        $this->assertSame(CalcJobExecution::STATUS_SUCCEEDED, $exec->status);

        // Повторное закрытие — no-op: ни нового run, ни нового окна.
        $this->closer()->closeHalfMonth('2026-07-H1');
        $this->assertSame(1, CalcRun::query()->where('period_id', $period->id)->count());
        $this->assertSame(1, CalcJobExecution::query()->where('window_key', '2026-07-H1')->count());
    }

    public function testCloseNotDuePeriodIsBlockedWithoutJobRow(): void
    {
        $this->travelTo(Carbon::parse('2026-07-17 00:10:00', 'UTC'));

        try {
            $this->closer()->closeHalfMonth('2026-07-H2'); // ещё идёт
            $this->fail('незавершённый период не должен закрываться');
        } catch (PeriodCloseBlockedException) {
        }

        $this->assertSame(CalcPeriod::STATUS_OPEN, $this->periods()->findByCode('2026-07-H2')->status);
        $this->assertSame(0, CalcJobExecution::query()->count(), 'ранний тик не плодит failed-окна');
        $this->assertSame(0, CalcRun::query()->count());
    }
}
