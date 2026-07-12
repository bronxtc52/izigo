<?php

namespace Modules\Calculator\V2\Services\Wallet;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\Policy\AccountRules;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Throwable;

/**
 * mh-full-plan T02: параметры блока accounts.* — из АКТИВНОЙ версии политики V2
 * через контракт T01 PolicyVersionResolver::forDate() (ревью W1 MF-5: хардкод
 * 7000bp/365д расходился бы с активированной политикой, которую заявляют снапшоты).
 *
 * Fail-safe: резолвер не забинден или активной версии нет → дефолты Гейта A из
 * канонического DefaultPolicyConfig (единственный источник дефолтов), с warning.
 * Потребители (WalletAccountsV2Service / OrderAccountPaymentService) не меняются.
 */
class AccountsPolicyV2
{
    public function __construct(private readonly Container $container)
    {
    }

    /** Максимум оплаты заказа с ОС, basis points. */
    public function osOrderPaymentMaxShareBp(\DateTimeInterface $at): int
    {
        return $this->rules($at)?->osMaxOrderPaymentShareBp
            ?? self::defaults()['os']['max_order_payment_share_bp'];
    }

    /** Срок жизни ОС-лота, дней (BR-ACC-001). */
    public function osLotLifetimeDays(\DateTimeInterface $at): int
    {
        return $this->rules($at)?->osLotLifetimeDays
            ?? self::defaults()['os']['lot_lifetime_days'];
    }

    /** Срок жизни БС-лота, дней (BR-ACC-004: 1 год с даты переноса). */
    public function bsLotLifetimeDays(\DateTimeInterface $at): int
    {
        return $this->rules($at)?->bsLotLifetimeDays
            ?? self::defaults()['bs']['lot_lifetime_days'];
    }

    /** accounts-правила активной на $at версии политики; null → fail-safe дефолты. */
    private function rules(\DateTimeInterface $at): ?AccountRules
    {
        if (! $this->container->bound(PolicyVersionResolver::class)) {
            return null;
        }

        try {
            return $this->container->make(PolicyVersionResolver::class)->forDate($at)->accounts();
        } catch (Throwable $e) {
            // Нет активной версии (PolicyNotActiveException T01) — кошелёк не должен
            // ронять оплату: fail-safe дефолты Гейта A, но не молча.
            Log::warning('V2 accounts: активная политика недоступна — fail-safe дефолты Гейта A', [
                'at' => $at->format(DATE_ATOM),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** @return array{os: array<string,mixed>, bs: array<string,mixed>} */
    private static function defaults(): array
    {
        static $accounts = null;

        return $accounts ??= DefaultPolicyConfig::doc()['accounts'];
    }
}
