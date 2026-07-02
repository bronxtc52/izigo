<?php

namespace Modules\Calculator\Services\Payout;

/**
 * Тестовый/dev драйвер выплат. Детерминированный tx_hash; статус — сразу confirmed.
 * Негативный/асинхронный пути включаются регистрацией адреса в статических списках
 * (B7: магические строки "FAIL"/"BROADCAST*" больше не проходят валидацию TON-адреса
 * при создании заявки — тестам нужны валидные адреса с назначенным поведением).
 */
class FakePayoutGateway implements PayoutGateway
{
    /** @var array<int,string> адреса, выплата на которые «падает» */
    public static array $failAddresses = [];

    /** @var array<int,string> адреса с асинхронным путём (подтверждение через poll) */
    public static array $broadcastAddresses = [];

    public static function reset(): void
    {
        self::$failAddresses = [];
        self::$broadcastAddresses = [];
    }

    public function send(string $toAddress, int $amountCents, string $ref): PayoutResult
    {
        if (in_array($toAddress, self::$failAddresses, true)) {
            return new PayoutResult(PayoutResult::FAILED, null, 'fake failure');
        }
        $txHash = 'faketx_' . substr(hash('sha256', $ref), 0, 16);
        if (in_array($toAddress, self::$broadcastAddresses, true)) {
            return new PayoutResult(PayoutResult::BROADCAST, $txHash);
        }

        return new PayoutResult(PayoutResult::CONFIRMED, $txHash);
    }

    public function status(string $txHash): string
    {
        return PayoutResult::CONFIRMED;
    }
}
