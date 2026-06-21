<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\AutoshipService;

/**
 * Прогон autoship-подписок, которым подошёл срок (Фаза 4, S6). Регистрируется в
 * расписании (daily). Списывает с внутреннего USDT-баланса, при нехватке — retry д.3/7/14.
 */
class AutoshipRunCommand extends Command
{
    protected $signature = 'commerce:autoship-run';

    protected $description = 'Charge due autoship subscriptions (Phase 4)';

    public function handle(AutoshipService $autoship): int
    {
        $summary = $autoship->runDue();
        $this->info(sprintf(
            'autoship: charged=%d retried=%d paused=%d',
            $summary['charged'],
            $summary['retried'],
            $summary['paused'],
        ));

        return self::SUCCESS;
    }
}
