<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\LeadService;

/**
 * Открепляет просроченных лидов (привязка к спонсору на lead_window_days истекла).
 * Лиды с «висящим» pending-платежом не трогаются (чекаут в полёте). По образцу TTL
 * pending-платежей: интерактивный путь страхуется ленивой проверкой в LeadService.
 */
class ExpireLeadsCommand extends Command
{
    protected $signature = 'leads:expire';

    protected $description = 'Открепить просроченных лидов (лид-окно истекло, покупки не было)';

    public function handle(LeadService $leads): void
    {
        $count = $leads->expireDue();
        $this->info("Откреплено просроченных лидов: {$count}");
    }
}
