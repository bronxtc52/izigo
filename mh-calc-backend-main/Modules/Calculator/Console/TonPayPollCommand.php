<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Services\PaymentService;

/**
 * Опрос «висящих» TON Pay платежей (Фаза 4, S3-TON). Non-custodial приём подтверждается
 * не webhook'ом, а валидацией on-chain: команда проверяет pending-платежи и при нахождении
 * входящей tx с верным memo/суммой исполняет заказ/пополнение.
 */
class TonPayPollCommand extends Command
{
    protected $signature = 'commerce:tonpay-poll';

    protected $description = 'Poll on-chain TON Pay payments (Phase 4)';

    public function handle(PaymentService $payments): int
    {
        $summary = $payments->pollPending();
        $this->info("tonpay-poll: confirmed={$summary['confirmed']} failed={$summary['failed']} expired={$summary['expired']} errors={$summary['errors']}");

        return self::SUCCESS;
    }
}
