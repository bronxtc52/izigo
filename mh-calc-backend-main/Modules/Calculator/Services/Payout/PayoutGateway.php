<?php

namespace Modules\Calculator\Services\Payout;

/**
 * Абстракция on-chain выплат (Фаза 4). Боевой драйвер — USDT в сети TON; тестовый — Fake.
 */
interface PayoutGateway
{
    /** Отправить выплату на адрес. $ref — наш идентификатор заявки ("wd:{id}"). */
    public function send(string $toAddress, int $amountCents, string $ref): PayoutResult;

    /** Текущий статус транзакции по tx_hash (для poll-команды). */
    public function status(string $txHash): string;
}
