<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\Monitoring\SchedulerHeartbeat;

/**
 * B-5 — лёгкий тик heartbeat планировщика (см. SchedulerHeartbeat).
 * Запланирован everyMinute: если schedule:run реально исполняется, метка остаётся свежей;
 * если планировщик умер/завис — метка протухает и /api/health отдаёт 503. Также зовётся
 * из docker/start.sh один раз при старте, чтобы health был зелёным ещё до первого тика.
 */
class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Оставить свежую метку живости планировщика Laravel (для /api/health)';

    public function handle(SchedulerHeartbeat $heartbeat): void
    {
        $ts = $heartbeat->touch();
        $this->info("Scheduler heartbeat: {$ts}");
    }
}
