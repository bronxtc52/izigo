<?php

namespace Modules\Calculator\Services\Payout;

use RuntimeException;

/**
 * Боевой драйвер выплат USDT в сети TON. Подписывает перевод USDT-джеттона ключом
 * hot-wallet (из Key Vault, env TON_PAYOUT_WALLET_KEY) и броадкастит через TON API.
 *
 * ⚠️ NEEDS-LIVE-VERIFY (Фаза 4): сборка/подпись jetton-transfer, адрес USDT-мастера в TON,
 * расчёт комиссии и эндпоинты TON API должны быть реализованы и проверены на тестнете с
 * боевым ключом ПЕРЕД включением в прод. Сейчас намеренно бросает, чтобы случайно не
 * «отправить» в проде без реализации. Тесты гоняют FakePayoutGateway.
 */
class UsdtTonPayoutGateway implements PayoutGateway
{
    public function __construct(
        private readonly string $walletKey,
        private readonly string $fromAddress,
        private readonly string $apiBaseUrl,
    ) {
    }

    public function send(string $toAddress, int $amountCents, string $ref): PayoutResult
    {
        if ($this->walletKey === '') {
            throw new RuntimeException('TON payout: ключ hot-wallet не сконфигурирован (Key Vault)');
        }

        // TODO(Фаза 4, NEEDS-LIVE-VERIFY): собрать и подписать jetton-transfer USDT,
        // броадкастнуть через TON API, вернуть PayoutResult с tx_hash/статусом.
        throw new RuntimeException('TON payout driver не реализован (NEEDS-LIVE-VERIFY)');
    }

    public function status(string $txHash): string
    {
        throw new RuntimeException('TON payout driver не реализован (NEEDS-LIVE-VERIFY)');
    }
}
