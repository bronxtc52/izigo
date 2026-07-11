<?php

namespace Modules\Calculator\V2\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;

/**
 * mh-full-plan T02: ежедневное сгорание кредит-лотов ОС/БС (00:20 UTC, DEC-019 — без
 * переноса на праздники). ОС-остаток → БС-лот (годовой срок с даты переноса), БС-остаток →
 * forfeit в company_expired_balance. Лоты с expires_at IS NULL пропускаются (MF-9).
 * Идемпотентен: повторный прогон не задваивает проводки (ключ v2:lot_expiry:{lot_id}).
 * No-op за выключенным фиче-флагом mh_plan_v2_engine (deny-by-default до cutover T15).
 */
class WalletLotsExpireCommand extends Command
{
    protected $signature = 'mh2:lots-expire';

    protected $description = 'V2: перенос/аннулирование истёкших кредит-лотов ОС/БС (mh-full-plan T02)';

    public function handle(FeatureFlagService $flags, WalletAccountsV2Service $wallet): int
    {
        if (! $flags->isEnabled('mh_plan_v2_engine')) {
            $this->info('mh2:lots-expire: фиче-флаг mh_plan_v2_engine выключен — no-op');

            return self::SUCCESS;
        }

        $processed = $wallet->expireLots();
        $this->info("mh2:lots-expire: обработано лотов: {$processed}");

        return self::SUCCESS;
    }
}
