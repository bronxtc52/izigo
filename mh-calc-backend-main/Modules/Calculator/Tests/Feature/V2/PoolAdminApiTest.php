<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Tests\TestCase;

/**
 * T11 [ПРАВА, negative обязательны]: admin-роуты 60%-калибровки. Deny-by-default:
 * без web.admin → 401; cabinet initData → 401; флаг mh_v2_pool OFF → 403 даже owner;
 * не owner/finance → 403; read — owner,finance; recalibrate — owner-only + аудит;
 * recalibrate на CLOSED периоде → 422; несуществующий/нерассчитанный месяц → 404.
 */
class PoolAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';
    private const BASE = '/api/v1/admin/v2/pool';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);
    }

    public function testPeriodsRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        $this->getJson(self::BASE . '/periods', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testCabinetInitDataRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(800, name: 'Root');
        $this->getJson(self::BASE . '/periods', $this->tgHeaders($data))->assertStatus(401);
    }

    public function testFlagOffBlocksEvenOwner(): void
    {
        [$data] = $this->registerTg(800, name: 'Root');
        $this->grantRole(800, 'owner');
        $this->getJson(self::BASE . '/periods', $this->adminHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testNonPrivilegedRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(801, name: 'X');
        $this->getJson(self::BASE . '/periods', $this->adminHeaders($data))->assertStatus(403);
    }

    public function testFinanceReadsButCannotRecalibrate(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(802, name: 'Fin');
        $this->grantRole(802, 'finance');
        $headers = $this->adminHeaders($data);

        $this->getJson(self::BASE . '/periods', $headers)->assertOk();
        $this->getJson(self::BASE . '/periods/' . self::MONTH, $headers)->assertStatus(404); // ещё не откалиброван

        // recalibrate — owner-only.
        $this->postJson(self::BASE . '/periods/' . self::MONTH . '/recalibrate', [], $headers)
            ->assertStatus(403);
    }

    public function testOwnerRecalibrateOpenMonthWritesAudit(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(800, name: 'Root');
        $this->grantRole(800, 'owner');

        $this->postJson(self::BASE . '/periods/' . self::MONTH . '/recalibrate', [], $this->adminHeaders($data))
            ->assertOk()
            ->assertJsonPath('data.month', self::MONTH)
            ->assertJsonPath('data.factor_bps', 10000); // пустой месяц → f=1

        $this->assertSame(1, AdminAuditLog::query()->where('action', 'v2.pool.recalibrate')->count());
        // Отчёт доступен после коммита.
        $this->getJson(self::BASE . '/periods/' . self::MONTH, $this->adminHeaders($data))
            ->assertOk()->assertJsonPath('data.month', self::MONTH);
        $this->getJson(self::BASE . '/periods/' . self::MONTH . '/members', $this->adminHeaders($data))
            ->assertOk();
    }

    public function testRecalibrateClosedPeriodReturns422(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(800, name: 'Root');
        $this->grantRole(800, 'owner');

        $period = CalcPeriod::query()->where('code', self::MONTH)->firstOrFail();
        $this->markPeriodClosed($period);

        $this->postJson(self::BASE . '/periods/' . self::MONTH . '/recalibrate', [], $this->adminHeaders($data))
            ->assertStatus(422);
    }

    public function testRecalibrateUnknownPeriodReturns404(): void
    {
        $this->enableFeatureFlags('mh_v2_pool');
        [$data] = $this->registerTg(800, name: 'Root');
        $this->grantRole(800, 'owner');

        $this->postJson(self::BASE . '/periods/2099-12/recalibrate', [], $this->adminHeaders($data))
            ->assertStatus(404);
    }
}
