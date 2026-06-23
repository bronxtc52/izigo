<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberAgreementAcceptance;
use Modules\Calculator\Models\PlanSetting;

/**
 * B3: пользовательское соглашение и онбординг-акцепт. Текст/версия — в plan_settings('agreement'),
 * факт акцепта — в member_agreement_acceptances. Версия растёт при правке текста: участники,
 * принявшие старую версию, должны принять заново. Аутентификация остаётся Telegram-only.
 */
class AgreementService
{
    private const KEY = 'agreement';
    private const DEFAULT_TEXT = 'Пользовательское соглашение пока не настроено администратором.';

    /** Поддерживаемые языки текста соглашения. Дефолт — ru, остальное падает в фолбэк. */
    private const SUPPORTED_LOCALES = ['ru', 'en'];
    private const DEFAULT_LOCALE = 'ru';

    /**
     * Текущая версия + локализованный текст соглашения.
     *
     * Формат хранения в plan_settings('agreement'):
     *   { "version": int, "text": { "ru": string, "en": string } }
     * Обратная совместимость: если в БД лежит старое значение, где text — строка, она
     * трактуется как text.ru (и en фолбэчится на ru). Фолбэк выбора языка:
     * запрошенный → ru → en → DEFAULT_TEXT.
     *
     * @param  ?string  $locale  Желаемый язык (ru/en); null — DEFAULT_LOCALE (ru).
     */
    public function current(?string $locale = null): array
    {
        $data = PlanSetting::get(self::KEY);
        $texts = $this->normalizeTexts($data['text'] ?? null);

        return [
            'version' => (int) ($data['version'] ?? 1),
            'text' => $this->pickText($texts, $locale),
        ];
    }

    /** Статус соглашения для участника: версия/текст (по языку) + принял ли он текущую версию. */
    public function statusFor(Member $member, ?string $locale = null): array
    {
        $current = $this->current($locale);
        $acceptedVersion = (int) (MemberAgreementAcceptance::query()
            ->where('member_id', $member->id)
            ->max('version') ?? 0);

        return [
            'version' => $current['version'],
            'text' => $current['text'],
            'accepted' => $acceptedVersion >= $current['version'],
            'accepted_version' => $acceptedVersion ?: null,
        ];
    }

    /** Принять текущую версию (идемпотентно по (member, version)). */
    public function accept(Member $member, ?string $locale = null): array
    {
        $current = $this->current();

        MemberAgreementAcceptance::query()->firstOrCreate(
            ['member_id' => $member->id, 'version' => $current['version']],
            ['accepted_at' => now()],
        );

        return $this->statusFor($member, $locale);
    }

    /**
     * Админ: обновить двуязычный текст соглашения → инкремент версии (требует повторного
     * акцепта). Принимает массив ['ru' => string, 'en' => string]; недостающий язык
     * фолбэчится на другой. Хранит структуру { version, text: { ru, en } }.
     *
     * @param  array{ru?:string,en?:string}  $texts
     */
    public function updateContent(array $texts): array
    {
        $normalized = $this->normalizeTexts($texts);
        $version = $this->current()['version'] + 1;
        PlanSetting::put(self::KEY, ['version' => $version, 'text' => $normalized]);

        return [
            'version' => $version,
            'text' => $this->pickText($normalized, self::DEFAULT_LOCALE),
            'texts' => $normalized,
        ];
    }

    /** Админ-сводка: текущая версия + оба текста (ru/en) + сколько участников приняли версию. */
    public function adminSummary(): array
    {
        $data = PlanSetting::get(self::KEY);
        $texts = $this->normalizeTexts($data['text'] ?? null);
        $version = (int) ($data['version'] ?? 1);

        $acceptedCount = MemberAgreementAcceptance::query()
            ->where('version', $version)
            ->distinct('member_id')
            ->count('member_id');

        return [
            'version' => $version,
            // text — дефолтный язык (ru) для обратной совместимости фронта;
            // texts — оба языка для админ-редактора.
            'text' => $this->pickText($texts, self::DEFAULT_LOCALE),
            'texts' => $texts,
            'accepted_current_count' => $acceptedCount,
            'members_total' => Member::query()->count(),
        ];
    }

    /**
     * Привести хранимое/входное значение text к структуре { ru, en }.
     * - строка (legacy) → ru = строка, en = ru (фолбэк);
     * - массив → берём ключи ru/en, недостающий язык фолбэчится на присутствующий;
     * - пусто → оба языка = DEFAULT_TEXT.
     *
     * @return array{ru:string,en:string}
     */
    private function normalizeTexts(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            $ru = $raw !== '' ? $raw : self::DEFAULT_TEXT;

            return ['ru' => $ru, 'en' => $ru];
        }

        if (is_array($raw)) {
            $ru = isset($raw['ru']) ? trim((string) $raw['ru']) : '';
            $en = isset($raw['en']) ? trim((string) $raw['en']) : '';

            if ($ru === '' && $en === '') {
                return ['ru' => self::DEFAULT_TEXT, 'en' => self::DEFAULT_TEXT];
            }

            // Недостающий язык фолбэчится на присутствующий, чтобы текст всегда был.
            $ru = $ru !== '' ? $ru : $en;
            $en = $en !== '' ? $en : $ru;

            return ['ru' => $ru, 'en' => $en];
        }

        return ['ru' => self::DEFAULT_TEXT, 'en' => self::DEFAULT_TEXT];
    }

    /**
     * Выбрать текст под язык с фолбэком: запрошенный → ru → en → DEFAULT_TEXT.
     *
     * @param  array{ru:string,en:string}  $texts
     */
    private function pickText(array $texts, ?string $locale): string
    {
        $locale = in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;

        $candidates = [$locale, self::DEFAULT_LOCALE, 'en'];
        foreach ($candidates as $code) {
            $value = trim((string) ($texts[$code] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return self::DEFAULT_TEXT;
    }
}
