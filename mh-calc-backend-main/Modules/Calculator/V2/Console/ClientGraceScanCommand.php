<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Services\Status\ClientLifecycleService;
use Modules\Calculator\V2\Services\Status\StatusesStep;

/**
 * T05: сканер просроченного grace CLIENT (BR-REG-004 / CAL-GRACE-001) —
 * amendments MF-7: state='client' AND grace_expires_at < now() AND
 * grace_outcome IS NULL. Каждый просроченный обрабатывается ОТДЕЛЬНОЙ
 * транзакцией с advisory-lock активаций (оркестратор события grace — берёт
 * ACTIVATION_LOCK, ClientLifecycleService лишь assertLockHeld). Идемпотентно:
 * повторный прогон не находит строк (grace_outcome уже annulled) => 0.
 * No-op при выключенном флаге mh_v2_statuses (deny-by-default).
 */
class ClientGraceScanCommand extends Command
{
    protected $signature = 'calc-v2:client-grace-scan {--limit=500 : максимум участников за прогон}';

    protected $description = 'Аннулировать grace-PV просроченных CLIENT (T05, BR-REG-004)';

    public function handle(FeatureFlagService $flags, ActivationService $activation, ClientLifecycleService $lifecycle): int
    {
        if (! $flags->isEnabled(StatusesStep::FLAG)) {
            $this->info('mh_v2_statuses OFF — grace-скан пропущен (no-op).');

            return self::SUCCESS;
        }

        $due = PartnerState::query()
            ->where('state', PartnerState::STATE_CLIENT)
            ->whereNull('grace_outcome')
            ->whereNotNull('grace_expires_at')
            ->where('grace_expires_at', '<', now())
            ->orderBy('member_id')
            ->limit((int) $this->option('limit'))
            ->pluck('member_id');

        $expired = 0;
        foreach ($due as $memberId) {
            DB::transaction(function () use ($activation, $lifecycle, $memberId, &$expired) {
                $activation->acquireActivationLock(); // pg_advisory_xact_lock — держится до конца транзакции
                if ($lifecycle->expireGrace((int) $memberId)) {
                    $expired++;
                }
            });
        }

        $this->info("Grace-скан: просрочено и аннулировано {$expired} из {$due->count()} кандидатов.");

        return self::SUCCESS;
    }
}
