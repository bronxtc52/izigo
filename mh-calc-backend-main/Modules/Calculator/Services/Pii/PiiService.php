<?php

namespace Modules\Calculator\Services\Pii;

/**
 * C5 (Block C): маскирование PII-полей участника.
 *
 * Список PII-полей фиксирован (Gate-A п.14): telegram_username, payout_details (TON-адрес),
 * KYC-данные. Маска применяется по умолчанию во всех сводках; реальные значения раскрываются
 * только owner через reveal (с аудитом). Этот сервис — единственная точка маскирования,
 * чтобы формат маски был консистентен в карточке и в экспорте.
 */
class PiiService
{
    public const TYPE_USERNAME = 'telegram_username';
    public const TYPE_PAYOUT = 'payout_details';
    public const TYPE_KYC = 'kyc';

    /** Фиксированный список PII-полей (Gate-A п.14). */
    public const PII_FIELDS = [
        self::TYPE_USERNAME,
        self::TYPE_PAYOUT,
        self::TYPE_KYC,
    ];

    /**
     * Замаскировать значение по типу PII. null/пусто отдаётся как есть (нечего скрывать).
     * Маски: username → `@ali***`; TON-адрес → `EQ...***...abc`; kyc/прочее → generic `***`.
     */
    public function mask(?string $value, string $type): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($type) {
            self::TYPE_USERNAME => $this->maskUsername($value),
            self::TYPE_PAYOUT => $this->maskAddress($value),
            default => $this->maskGeneric($value),
        };
    }

    /** `@username` → `@use***` (показываем @ + до 3 первых символов хвоста). */
    private function maskUsername(string $value): string
    {
        $hasAt = str_starts_with($value, '@');
        $body = $hasAt ? substr($value, 1) : $value;
        $head = mb_substr($body, 0, 3);

        // Маску всегда нормализуем к виду `@xxx***` (с ведущим @), даже если на входе @ не было.
        return '@' . $head . '***';
    }

    /** TON-адрес `EQ…abc` → `EQ...***...abc` (первые 2 + последние 3 символа). */
    private function maskAddress(string $value): string
    {
        if (mb_strlen($value) <= 5) {
            return '***';
        }
        $head = mb_substr($value, 0, 2);
        $tail = mb_substr($value, -3);

        return $head . '...***...' . $tail;
    }

    /** Generic-маска (KYC и прочее): полностью скрыть значение. */
    private function maskGeneric(string $value): string
    {
        return '***';
    }
}
