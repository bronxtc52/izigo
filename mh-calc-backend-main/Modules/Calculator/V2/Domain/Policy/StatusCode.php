<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: 12 статусов лестницы MH (CLIENT..VICE_PRESIDENT) с фиксированным ordinal 0-11.
 * Порядок канонический (07_Rules_Config.example.yaml status_order + план Гейта A);
 * ordinal используется T08 для rank-gap блока (DEC-030) и T05 для монотонности рангов.
 */
enum StatusCode: string
{
    case CLIENT = 'CLIENT';
    case CONSULTANT = 'CONSULTANT';
    case MANAGER = 'MANAGER';
    case BRONZE_MANAGER = 'BRONZE_MANAGER';
    case SILVER_MANAGER = 'SILVER_MANAGER';
    case GOLD_MANAGER = 'GOLD_MANAGER';
    case PLATINUM_MANAGER = 'PLATINUM_MANAGER';
    case DIRECTOR = 'DIRECTOR';
    case PEARL_DIRECTOR = 'PEARL_DIRECTOR';
    case SAPPHIRE_DIRECTOR = 'SAPPHIRE_DIRECTOR';
    case DIAMOND_DIRECTOR = 'DIAMOND_DIRECTOR';
    case VICE_PRESIDENT = 'VICE_PRESIDENT';

    public function ordinal(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** Канонический порядок кодов (ordinal 0..11). */
    public static function orderedCodes(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
