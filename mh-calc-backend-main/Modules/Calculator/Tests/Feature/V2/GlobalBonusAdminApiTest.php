<?php

namespace Modules\Calculator\Tests\Feature\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calculator\Models\AdminAuditLog;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Modules\Calculator\Tests\Feature\V2\Support\SeedsV2GlobalBonus;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Tests\TestCase;

/**
 * T09 [ПРАВА, negative обязательны]: admin-роуты глобального бонуса.
 * Deny-by-default: без web.admin → 401; флаг mh_v2_global_bonus OFF → 403 даже owner;
 * не owner/finance → 403; read — owner,finance; пересчёт — owner-only + аудит;
 * пересчёт финального месяца → 409.
 */
class GlobalBonusAdminApiTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;
    use SeedsV2GlobalBonus;

    private const MONTH = '2026-03';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
        $this->activateGlobalBonusPolicy();
        $this->ensurePeriod(self::MONTH);
    }

    public function testMonthsRequiresAuth(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        $this->getJson('/api/v1/admin/v2/global-bonus/months', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(401);
    }

    public function testAdminCabinetInitDataRejected(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        [$data] = $this->registerTg(700, name: 'Root');
        // initData (cabinet) не проходит web.admin (нужен Sanctum Bearer).
        $this->getJson('/api/v1/admin/v2/global-bonus/months', $this->tgHeaders($data))
            ->assertStatus(401);
    }

    public function testFlagOffBlocksEvenOwner(): void
    {
        [$data] = $this->registerTg(700, name: 'Root');
        $this->grantRole(700, 'owner');
        $this->getJson('/api/v1/admin/v2/global-bonus/months', $this->adminHeaders($data))
            ->assertStatus(403)->assertJsonPath('code', 'FEATURE_DISABLED');
    }

    public function testNonPrivilegedRoleGets403(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        [$data] = $this->registerTg(701, name: 'X');
        $this->getJson('/api/v1/admin/v2/global-bonus/months', $this->adminHeaders($data))
            ->assertStatus(403);
    }

    public function testFinanceReadsButCannotRecompute(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        [$data] = $this->registerTg(702, name: 'Fin');
        $this->grantRole(702, 'finance');
        $headers = $this->adminHeaders($data);

        $this->getJson('/api/v1/admin/v2/global-bonus/months', $headers)->assertOk();
        $this->getJson('/api/v1/admin/v2/global-bonus/months/' . self::MONTH, $headers)->assertStatus(404); // ещё не рассчитан
        $this->getJson('/api/v1/admin/v2/global-bonus/quarters/2026-Q1', $headers)->assertStatus(404);

        // recompute — owner-only.
        $this->postJson('/api/v1/admin/v2/global-bonus/months/' . self::MONTH . '/recompute', [], $headers)
            ->assertStatus(403);
    }

    public function testOwnerRecomputeWritesAudit(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        [$data] = $this->registerTg(700, name: 'Root');
        $this->grantRole(700, 'owner');

        $resp = $this->postJson('/api/v1/admin/v2/global-bonus/months/' . self::MONTH . '/recompute', [], $this->adminHeaders($data))
            ->assertOk();
        $resp->assertJsonPath('data.status', GlobalBonusMonth::STATUS_DRAFT);

        $this->assertSame(1, AdminAuditLog::query()->where('action', 'v2.global_bonus.recompute')->count());
        // Месяц + отчёт доступны после пересчёта.
        $this->getJson('/api/v1/admin/v2/global-bonus/months/' . self::MONTH, $this->adminHeaders($data))
            ->assertOk()->assertJsonPath('data.status', GlobalBonusMonth::STATUS_DRAFT);
    }

    public function testRecomputeFinalMonthReturns409(): void
    {
        $this->enableFeatureFlags('mh_v2_global_bonus');
        [$data] = $this->registerTg(700, name: 'Root');
        $this->grantRole(700, 'owner');

        // Финализируем месяц напрямую.
        $period = CalcPeriod::query()->where('code', self::MONTH)->firstOrFail();
        $service = app(\Modules\Calculator\V2\Services\GlobalBonus\GlobalBonusMonthlyService::class);
        $service->allocateForMonth($period);
        $service->finalizeMonth($period);

        $this->postJson('/api/v1/admin/v2/global-bonus/months/' . self::MONTH . '/recompute', [], $this->adminHeaders($data))
            ->assertStatus(409);
    }
}
