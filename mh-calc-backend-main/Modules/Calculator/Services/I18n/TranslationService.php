<?php

namespace Modules\Calculator\Services\I18n;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Modules\Calculator\Models\TranslationOverride;

/**
 * C4 (Block C): редактируемые переводы. Источник истины — таблица translation_overrides
 * (sparse: только переопределённые строки). Фронт мёржит карту key→value поверх статических
 * locale-JSON. Кэш карты по locale с коротким TTL (страховка от рассинхрона single-replica);
 * при upsert/delete явно сбрасываем — как FeatureFlagService (C3).
 *
 * Запретные зоны не трогаем: Modules/ConfigIziGo (серверные locales) — ВНЕ скоупа C4.
 */
class TranslationService
{
    /** Поддерживаемые локали фронта (az,kk,ky,mn,ru,uz). Источник — статические locale-JSON. */
    public const LOCALES = ['az', 'kk', 'ky', 'mn', 'ru', 'uz'];

    /** Короткий TTL — страховка, не основной механизм инвалидации (сбрасываем явно). */
    private const CACHE_TTL = 60;

    /** Предсказуемый кэш-ключ карты оверрайдов одной локали. */
    private static function cacheKey(string $locale): string
    {
        return "calculator.translation_overrides.{$locale}";
    }

    /** Карта оверрайдов key→value для локали (для фронт-мёржа поверх статики). */
    public function overridesForLocale(string $locale): array
    {
        $locale = $this->assertLocale($locale);

        return Cache::remember(
            self::cacheKey($locale),
            self::CACHE_TTL,
            fn () => TranslationOverride::query()
                ->where('locale', $locale)
                ->pluck('value', 'key')
                ->all(),
        );
    }

    /** Оверрайды всех локалей: locale→(key→value). Для разовой загрузки фронтом. */
    public function allOverrides(): array
    {
        $out = [];
        foreach (self::LOCALES as $locale) {
            $map = $this->overridesForLocale($locale);
            if (! empty($map)) {
                $out[$locale] = $map;
            }
        }

        return $out;
    }

    /**
     * Полный список оверрайдов для админки (с метаданными). Опционально фильтр по локали.
     *
     * @return array<int,array{id:int,locale:string,key:string,value:string,updated_at:?string}>
     */
    public function list(?string $locale = null): array
    {
        $query = TranslationOverride::query()->orderBy('locale')->orderBy('key');
        if ($locale !== null) {
            $query->where('locale', $this->assertLocale($locale));
        }

        return $query->get(['id', 'locale', 'key', 'value', 'updated_at'])
            ->map(fn (TranslationOverride $o) => [
                'id' => $o->id,
                'locale' => $o->locale,
                'key' => $o->key,
                'value' => $o->value,
                'updated_at' => $o->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Создать/обновить оверрайд (locale,key)→value. UNIQUE(locale,key) соблюдается через
     * upsert по уникальной паре — повторный вызов обновляет, не дублирует. Сбрасывает кэш.
     */
    public function upsert(string $locale, string $key, string $value, ?int $actorId = null): array
    {
        $locale = $this->assertLocale($locale);
        $key = $this->assertKey($key);

        $override = TranslationOverride::query()->firstOrNew([
            'locale' => $locale,
            'key' => $key,
        ]);
        $override->value = $value;
        if ($actorId !== null) {
            $override->updated_by = $actorId;
        }
        $override->save();

        Cache::forget(self::cacheKey($locale));

        return [
            'id' => $override->id,
            'locale' => $override->locale,
            'key' => $override->key,
            'value' => $override->value,
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }

    /** Удалить оверрайд (вернуть строку к статическому дефолту). Сбрасывает кэш. */
    public function delete(string $locale, string $key): void
    {
        $locale = $this->assertLocale($locale);
        $key = $this->assertKey($key);

        TranslationOverride::query()
            ->where('locale', $locale)
            ->where('key', $key)
            ->delete();

        Cache::forget(self::cacheKey($locale));
    }

    /** Валидация локали (только поддерживаемые), иначе 422 у контроллера. */
    private function assertLocale(string $locale): string
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException('Неизвестная локаль: ' . $locale);
        }

        return $locale;
    }

    /** Валидация ключа (непустой). */
    private function assertKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            throw new InvalidArgumentException('Пустой ключ перевода');
        }

        return $key;
    }
}
