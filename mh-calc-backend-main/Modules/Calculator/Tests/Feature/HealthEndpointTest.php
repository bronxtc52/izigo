<?php

namespace Modules\Calculator\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Modules\Calculator\Services\Monitoring\SchedulerHeartbeat;
use Tests\TestCase;

/**
 * B-5 + M1: health-эндпоинт /api/health + liveness /up.
 *  - 200 при доступной БД И свежем heartbeat планировщика;
 *  - 503 при протухшем ИЛИ отсутствующем heartbeat (сигнал «встал планировщик»).
 * БД в тест-окружении — реальный Postgres (см. CLAUDE.md), select 1 работает и без миграций,
 * поэтому RefreshDatabase не нужен. Кэш в тестах — array-драйвер (в проде — file).
 */
class HealthEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);
    }

    public function testHealthOkWhenSchedulerHeartbeatIsFresh(): void
    {
        // Свежая метка = планировщик тикал только что.
        app(SchedulerHeartbeat::class)->touch();

        $this->getJson('/api/health')
            ->assertStatus(200)
            ->assertJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok', 'scheduler' => 'ok'],
            ]);
    }

    public function testHealth503WhenHeartbeatMissing(): void
    {
        // Метки нет вовсе (планировщик ни разу не тикнул / кэш пуст).
        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'checks' => ['scheduler' => 'no-heartbeat'],
            ]);
    }

    public function testHealth503WhenHeartbeatIsStale(): void
    {
        // Протухшая метка = планировщик умер/завис (старше порога свежести).
        Cache::put(
            SchedulerHeartbeat::CACHE_KEY,
            time() - (SchedulerHeartbeat::FRESH_SECONDS + 60),
            3600
        );

        $this->getJson('/api/health')
            ->assertStatus(503)
            ->assertJson([
                'status' => 'unhealthy',
                'checks' => ['scheduler' => 'stale'],
            ]);
    }

    public function testLivenessUpAlwaysOk(): void
    {
        $this->getJson('/up')
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }
}
