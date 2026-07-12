<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use Modules\Calculator\V2\Domain\Policy\GlobalPoolRule;
use Modules\Calculator\V2\Domain\Policy\StatusCode;

/**
 * T09: типизированный ридер секции global_pool политики (T01 GlobalPoolRule).
 * Инкапсулирует маппинг статус-кодов лестницы на короткие коды пулов
 * (director..vp), порядок пулов по ordinal и параметры долей/капа.
 *
 * Всё — из активной PolicyV2 (провенанс версии в снапшоте месяца); хардкода ставок
 * нет, конфиг effective-dated (условия могут меняться раз в год, PPTX:S30).
 */
final class GlobalBonusConfig
{
    /** Статус-код лестницы → короткий код пула (v2_global_bonus_pools.pool_rank). */
    private const POOL_RANK_BY_STATUS = [
        StatusCode::DIRECTOR->value => 'director',
        StatusCode::PEARL_DIRECTOR->value => 'pearl',
        StatusCode::SAPPHIRE_DIRECTOR->value => 'sapphire',
        StatusCode::DIAMOND_DIRECTOR->value => 'diamond',
        StatusCode::VICE_PRESIDENT->value => 'vp',
    ];

    public function __construct(private readonly GlobalPoolRule $rule)
    {
    }

    /** Статус-коды пулов в порядке возрастания ordinal (Director → VP). */
    public function poolStatusCodes(): array
    {
        return array_keys(self::POOL_RANK_BY_STATUS);
    }

    /** Короткий код пула для статус-кода, либо null если статус не пуловый (< Director). */
    public function poolRankFor(string $statusCode): ?string
    {
        return self::POOL_RANK_BY_STATUS[$statusCode] ?? null;
    }

    /** Участвует ли статус в глобальном бонусе (rank >= Director). */
    public function isPoolStatus(string $statusCode): bool
    {
        return isset(self::POOL_RANK_BY_STATUS[$statusCode]);
    }

    public function rateBpsFor(string $statusCode): int
    {
        return (int) ($this->rule->pools[$statusCode]['rate_bp'] ?? 0);
    }

    /** База PV одной доли (one_share_pv_min) для статуса-владельца. */
    public function oneShareBaseFor(string $statusCode): int
    {
        return (int) ($this->rule->pools[$statusCode]['one_share_pv_min'] ?? 0);
    }

    public function maxShares(): int
    {
        return $this->rule->maxShares;
    }

    public function memberCapBp(): int
    {
        return $this->rule->memberCapBp;
    }

    public function inheritsLowerPools(): bool
    {
        return $this->rule->inheritsLowerPools;
    }

    public function includePersonalPv(): bool
    {
        return $this->rule->includePersonalPv;
    }

    /**
     * Пуловые статус-коды на уровне статуса-владельца и НИЖЕ (наследование пулов):
     * Sapphire → [director, pearl, sapphire]. Если наследование выключено — только
     * собственный пул. Порядок соблюдён (ordinal возрастающий).
     *
     * @return string[] статус-коды пулов, в которые попадают доли владельца
     */
    public function poolsAtOrBelow(string $ownerStatusCode): array
    {
        if (! $this->isPoolStatus($ownerStatusCode)) {
            return [];
        }
        if (! $this->inheritsLowerPools()) {
            return [$ownerStatusCode];
        }

        $ownerOrdinal = StatusCode::from($ownerStatusCode)->ordinal();
        $result = [];
        foreach ($this->poolStatusCodes() as $code) {
            if (StatusCode::from($code)->ordinal() <= $ownerOrdinal) {
                $result[] = $code;
            }
        }

        return $result;
    }
}
