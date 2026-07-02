<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Calculator\Database\Seeders\FeatureFlagSeeder;
use Modules\Calculator\Models\FeatureFlag;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\Tests\Feature\Concerns\SignsTelegramInitData;
use Tests\TestCase;

/**
 * B5 (P1-hardening): фиче-флаги c1..c7 энфорсятся НА БЭКЕНДЕ (middleware feature.flag),
 * а не только скрытием табов на фронте. Выключенный/несуществующий флаг = 403
 * FEATURE_DISABLED на прямой запрос к API.
 */
class FeatureFlagEnforcementTest extends TestCase
{
    use RefreshDatabase;
    use SignsTelegramInitData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTelegram();
    }

    /** Характерный cabinet-роут на каждый cabinet-гейченный флаг. */
    public static function cabinetRoutes(): array
    {
        return [
            'c1 notifications' => ['c1_notifications', '/api/v1/cabinet/notifications'],
            'c2 helpdesk' => ['c2_helpdesk', '/api/v1/cabinet/tickets'],
            'c6 copartners' => ['c6_copartners', '/api/v1/cabinet/copartners'],
        ];
    }

    /** Характерный admin-роут на каждый admin-гейченный флаг (owner проходит RBAC всегда). */
    public static function adminRoutes(): array
    {
        return [
            'c1 broadcasts' => ['c1_notifications', 'POST', '/api/v1/admin/broadcasts/preview', ['segment_type' => 'all']],
            'c2 helpdesk admin' => ['c2_helpdesk', 'GET', '/api/v1/admin/tickets', []],
            'c4 i18n admin' => ['c4_i18n_admin', 'GET', '/api/v1/admin/i18n/overrides', []],
            'c7 monitoring' => ['c7_jobs_monitor', 'GET', '/api/v1/admin/monitoring/outbox', []],
        ];
    }

    /** @dataProvider cabinetRoutes */
    public function testCabinetRouteForbiddenWhenFlagOff(string $flag, string $uri): void
    {
        [$data] = $this->registerTg(4000, name: 'A');

        $this->getJson($uri, $this->tgHeaders($data))
            ->assertForbidden()
            ->assertJsonPath('code', 'FEATURE_DISABLED');

        app(FeatureFlagService::class)->set($flag, true);
        $this->getJson($uri, $this->tgHeaders($data))->assertOk();
    }

    /** @dataProvider adminRoutes */
    public function testAdminRouteForbiddenWhenFlagOff(string $flag, string $method, string $uri, array $payload): void
    {
        [$ownerData] = $this->registerTg(4010, name: 'Owner');
        $this->grantRole(4010, 'owner');
        $headers = $this->adminHeaders($ownerData);

        $res = $method === 'GET' ? $this->getJson($uri, $headers) : $this->postJson($uri, $payload, $headers);
        $res->assertForbidden()->assertJsonPath('code', 'FEATURE_DISABLED');

        app(FeatureFlagService::class)->set($flag, true);
        $res = $method === 'GET' ? $this->getJson($uri, $headers) : $this->postJson($uri, $payload, $headers);
        $res->assertOk();
    }

    public function testPiiExportForbiddenWhenFlagOff(): void
    {
        // c5 отдельно: нужен целевой участник.
        [$ownerData, $ownerRef] = $this->registerTg(4020, name: 'Owner');
        $this->grantRole(4020, 'owner');
        $this->registerTg(4021, $ownerRef, 'Target');
        $targetId = $this->memberByTg(4021)->id;
        $uri = "/api/v1/admin/members/{$targetId}/pii";

        $this->getJson($uri, $this->adminHeaders($ownerData))
            ->assertForbidden()->assertJsonPath('code', 'FEATURE_DISABLED');

        app(FeatureFlagService::class)->set('c5_pii_export', true);
        $this->getJson($uri, $this->adminHeaders($ownerData))->assertOk();
    }

    // --- Сознательные исключения из enforcement (см. plan.md, лог допущений) ---

    public function testPublicI18nOverridesNotGatedByC4(): void
    {
        // c4 выключает админ-управление переводами, но не runtime-serving: строки нужны
        // фронту (в т.ч. логин-странице) при любом состоянии флага.
        $this->getJson('/api/v1/i18n/overrides')->assertOk();
    }

    public function testFeatureFlagsActiveEndpointNotGated(): void
    {
        // Источник истины для фронта о включённых фичах — обязан отвечать всегда.
        [$data] = $this->registerTg(4030, name: 'A');
        $this->getJson('/api/v1/cabinet/feature-flags', $this->tgHeaders($data))->assertOk();
    }

    public function testFeatureFlagAdminNotGatedButOwnerOnly(): void
    {
        // c3 by design без флага (иначе выключенный c3 нельзя включить обратно), но owner-only.
        [$ownerData] = $this->registerTg(4040, name: 'Owner');
        $this->grantRole(4040, 'owner');
        $this->getJson('/api/v1/admin/feature-flags', $this->adminHeaders($ownerData))->assertOk();
    }

    // --- Страховка от осиротевшего алиаса ---

    public function testEveryRouteFlagAliasExistsInSeeder(): void
    {
        // Опечатка в алиасе middleware = перманентный 403 (deny-by-default) на всю фичу.
        $this->seed(FeatureFlagSeeder::class);
        $seeded = FeatureFlag::query()->pluck('key')->all();

        $aliases = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && str_starts_with($mw, 'feature.flag:')) {
                    $aliases[substr($mw, strlen('feature.flag:'))] = true;
                }
            }
        }

        $this->assertNotEmpty($aliases, 'feature.flag middleware не найден ни на одном роуте');
        foreach (array_keys($aliases) as $alias) {
            $this->assertContains($alias, $seeded, "Алиас {$alias} из роутов отсутствует в FeatureFlagSeeder");
        }
    }
}
