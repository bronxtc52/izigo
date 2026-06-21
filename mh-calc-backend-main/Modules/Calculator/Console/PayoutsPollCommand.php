<?php

namespace Modules\Calculator\Console;

use Illuminate\Console\Command;
use Modules\Calculator\Models\PayoutTransaction;
use Modules\Calculator\Services\Payout\PayoutGateway;
use Modules\Calculator\Services\Payout\PayoutResult;
use Modules\Calculator\Services\WithdrawalService;

/**
 * Опрос статусов on-chain выплат (Фаза 4, S7). Для broadcast-транзакций спрашивает сеть и
 * финализирует заявку через WithdrawalService::reconcilePayout: confirmed → paid,
 * failed → возврат холда + cancelled (идемпотентно, под блокировкой).
 */
class PayoutsPollCommand extends Command
{
    protected $signature = 'commerce:payouts-poll';

    protected $description = 'Poll on-chain payout confirmations (Phase 4)';

    public function handle(PayoutGateway $gateway, WithdrawalService $withdrawals): int
    {
        $pending = PayoutTransaction::query()
            ->where('status', PayoutTransaction::STATUS_BROADCAST)
            ->whereNotNull('tx_hash')
            ->get();

        $updated = 0;
        foreach ($pending as $tx) {
            $status = $gateway->status((string) $tx->tx_hash);
            if ($status === PayoutResult::CONFIRMED || $status === PayoutResult::FAILED) {
                $withdrawals->reconcilePayout($tx->id, $status);
                $updated++;
            }
        }

        $this->info("payouts-poll: updated={$updated}");

        return self::SUCCESS;
    }
}
