<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Services\Periods\PeriodService;
use Tests\TestCase;

/**
 * T04, БЕЗОПАСНОСТЬ (negative-cases обязательны): admin-роуты периодов —
 * deny-by-default фиче-флагом mh_plan_v2_admin (каркас W0), auth web.admin,
 * RBAC: read — owner|finance, mutation (close) — только owner.
 */
class PeriodAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    private string $ownerInit;
    private string $financeInit;
    private string $memberInit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();

        [$this->ownerInit] = $this->registerTg(5001, name: 'Owner');
        $this->grantRole(5001, 'owner');
        [$this->financeInit] = $this->registerTg(5002, name: 'Fin');
        $this->grantRole(5002, 'finance');
        [$this->memberInit] = $this->registerTg(5003, name: 'Plain');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function testUnauthenticatedRequestRejected(): void
    {
        $this->getJson('/api/v1/admin/v2/periods')->assertStatus(401);
    }

    public function testFlagOffDeniesEvenOwner(): void
    {
        $this->getJson('/api/v1/admin/v2/periods', $this->adminHeaders($this->ownerInit))
            ->assertForbidden()
            ->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testRoleEnforcement(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $period = app(PeriodService::class)->ensureByCode('2026-07-H1');

        // Участник без роли — 403 на read и mutation.
        $this->getJson('/api/v1/admin/v2/periods', $this->adminHeaders($this->memberInit))->assertForbidden();
        $this->postJson("/api/v1/admin/v2/periods/{$period->id}/close", [], $this->adminHeaders($this->memberInit))->assertForbidden();

        // finance: read — да, mutation — нет.
        $this->getJson('/api/v1/admin/v2/periods', $this->adminHeaders($this->financeInit))->assertOk();
        $this->getJson("/api/v1/admin/v2/periods/{$period->id}", $this->adminHeaders($this->financeInit))->assertOk();
        $this->postJson("/api/v1/admin/v2/periods/{$period->id}/close", [], $this->adminHeaders($this->financeInit))->assertForbidden();

        $this->assertSame(CalcPeriod::STATUS_OPEN, $period->refresh()->status, 'не-owner не закрыл период');
    }

    public function testOwnerCloseIsIdempotent(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $period = app(PeriodService::class)->ensureByCode('2026-07-H1');

        $first = $this->postJson("/api/v1/admin/v2/periods/{$period->id}/close", [], $this->adminHeaders($this->ownerInit));
        $first->assertOk()
            ->assertJsonPath('data.status', CalcPeriod::STATUS_CLOSED)
            ->assertJsonPath('data.closed_by', 'system')
            ->assertJsonPath('data.runs_count', 1);

        // Повтор — no-op: тот же run, никаких новых закрытий.
        $second = $this->postJson("/api/v1/admin/v2/periods/{$period->id}/close", [], $this->adminHeaders($this->ownerInit));
        $second->assertOk()->assertJsonPath('data.runs_count', 1);
    }

    public function testCloseNotDuePeriodReturns422(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $current = app(PeriodService::class)->ensureByCode('2026-07-H2'); // ещё идёт

        $this->postJson("/api/v1/admin/v2/periods/{$current->id}/close", [], $this->adminHeaders($this->ownerInit))
            ->assertStatus(422);
        $this->assertSame(CalcPeriod::STATUS_OPEN, $current->refresh()->status);
    }

    public function testMonthCloseBlockedReturns409(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->travelTo(Carbon::parse('2026-08-01 00:30:00', 'UTC'));
        $month = app(PeriodService::class)->ensureByCode('2026-07'); // halves не закрыты

        $this->postJson("/api/v1/admin/v2/periods/{$month->id}/close", [], $this->adminHeaders($this->ownerInit))
            ->assertStatus(409);
        $this->assertSame(CalcPeriod::STATUS_OPEN, $month->refresh()->status);
    }

    public function testIndexFiltersAndShowShape(): void
    {
        $this->enableFeatureFlags('mh_plan_v2_admin');
        $this->travelTo(Carbon::parse('2026-07-20 00:10:00', 'UTC'));
        $period = app(PeriodService::class)->ensureByCode('2026-07-H1');
        app(PeriodService::class)->ensureByCode('2026-07');

        $this->getJson('/api/v1/admin/v2/periods?type=half_month&status=open', $this->adminHeaders($this->ownerInit))
            ->assertOk()
            ->assertJsonPath('data.0.code', '2026-07-H1')
            ->assertJsonCount(1, 'data');

        // Закрываем и смотрим show: runs + мета снапшота (контракт чтения T13).
        $this->postJson("/api/v1/admin/v2/periods/{$period->id}/close", [], $this->adminHeaders($this->ownerInit))->assertOk();

        $show = $this->getJson("/api/v1/admin/v2/periods/{$period->id}", $this->adminHeaders($this->ownerInit))
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-07-H1', $show['code']);
        $this->assertSame(1, $show['runs_count'], 'NTH-6 ревью W1: show() обязан отдавать реальный runs_count, а не 0');
        $this->assertCount(1, $show['runs']);
        $this->assertSame('close', $show['runs'][0]['mode']);
        $this->assertSame('succeeded', $show['runs'][0]['status']);
        $this->assertNotEmpty($show['runs'][0]['result_hash']);
        $this->assertNotEmpty($show['runs'][0]['snapshot']['payload_hash']);
    }
}
