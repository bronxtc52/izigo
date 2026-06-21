<?php

namespace Modules\Calculator\Services\Payout;

/**
 * Тестовый/dev драйвер выплат. Детерминированный tx_hash; статус — сразу confirmed.
 * Для проверки негативного пути адрес "FAIL" даёт failed.
 */
class FakePayoutGateway implements PayoutGateway
{
    public function send(string $toAddress, int $amountCents, string $ref): PayoutResult
    {
        if ($toAddress === 'FAIL') {
            return new PayoutResult(PayoutResult::FAILED, null, 'fake failure');
        }
        $txHash = 'faketx_' . substr(hash('sha256', $ref), 0, 16);
        // Адрес BROADCAST* → асинхронный путь (подтверждение через poll).
        if (str_starts_with($toAddress, 'BROADCAST')) {
            return new PayoutResult(PayoutResult::BROADCAST, $txHash);
        }

        return new PayoutResult(PayoutResult::CONFIRMED, $txHash);
    }

    public function status(string $txHash): string
    {
        return PayoutResult::CONFIRMED;
    }
}
