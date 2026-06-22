<?php

namespace Modules\Calculator\Services\FeatureFlag;

use Illuminate\Support\Facades\Cache;
use Modules\Calculator\Models\FeatureFlag;

/**
 * C3 (Block C): рантайм-тоглы. Источник истины — таблица feature_flags (deny-by-default:
 * отсутствующий/выключенный ключ = false). Кэш карты ключ→enabled с коротким TTL как
 * страховка (прод single-replica, драйвер file/array); при изменении явно сбрасываем.
 *
 * Запретные зоны не трогаем: флаги читают только фичи, к движку бонусов отношения нет.
 */
class FeatureFlagService
{
    /** Предсказуемый кэш-ключ карты всех флагов. */
    private const CACHE_KEY = 'calculator.feature_flags.map';

    /** Короткий TTL — страховка от рассинхрона, не основной механизм инвалидации. */
    private const CACHE_TTL = 60;

    /** Включён ли флаг. Неизвестный/выключенный ключ → false (deny-by-default). */
    public function isEnabled(string $key): bool
    {
        return $this->map()[$key] ?? false;
    }

    /** Карта всех флагов ключ→bool (для cabinet-чтения активных и общего использования). */
    public function all(): array
    {
        return $this->map();
    }

    /** Только включённые флаги ключ→true (для cabinet/Mini App). */
    public function enabled(): array
    {
        return array_filter($this->map());
    }

    /** Полный список флагов с описанием (для админ-управления). */
    public function list(): array
    {
        return FeatureFlag::query()
            ->orderBy('key')
            ->get(['key', 'enabled', 'description', 'updated_at'])
            ->map(fn (FeatureFlag $f) => [
                'key' => $f->key,
                'enabled' => (bool) $f->enabled,
                'description' => $f->description,
                'updated_at' => $f->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Установить значение флага. Создаёт запись, если ключа нет (toggle ранее не
     * засиженного флага). Сбрасывает кэш только при реальном изменении значения.
     */
    public function set(string $key, bool $enabled, ?int $actorId = null): void
    {
        $flag = FeatureFlag::query()->firstOrNew(['key' => $key]);
        $changed = ! $flag->exists || (bool) $flag->enabled !== $enabled;

        $flag->enabled = $enabled;
        if ($actorId !== null) {
            $flag->updated_by = $actorId;
        }
        $flag->save();

        if ($changed) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    /** Карта ключ→bool из кэша (короткий TTL) либо из БД. */
    private function map(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => FeatureFlag::query()
                ->pluck('enabled', 'key')
                ->map(fn ($v) => (bool) $v)
                ->all(),
        );
    }
}
