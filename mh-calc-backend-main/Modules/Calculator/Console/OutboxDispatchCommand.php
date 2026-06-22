<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\Notification\OutboxDispatcher;

/**
 * C1 (Block C) — диспетчер outbox уведомлений. Фон проекта = планировщик (НЕ queue):
 * зарегистрирована everyMinute()->withoutOverlapping(5) в CalculatorServiceProvider.
 * Шлёт pending-уведомления (available_at<=now) через TelegramNotifier.
 */
class OutboxDispatchCommand extends Command
{
    protected $signature = 'notifications:outbox-dispatch';

    protected $description = 'Dispatch pending notification outbox messages (C1, Block C)';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $stats = $dispatcher->dispatch();
        $this->info(sprintf(
            'outbox-dispatch: processed=%d sent=%d failed=%d skipped=%d',
            $stats['processed'],
            $stats['sent'],
            $stats['failed'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
