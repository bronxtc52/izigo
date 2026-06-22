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

    /** Текущая версия и текст соглашения. */
    public function current(): array
    {
        $data = PlanSetting::get(self::KEY);

        return [
            'version' => (int) ($data['version'] ?? 1),
            'text' => (string) ($data['text'] ?? self::DEFAULT_TEXT),
        ];
    }

    /** Статус соглашения для участника: версия/текст + принял ли он текущую версию. */
    public function statusFor(Member $member): array
    {
        $current = $this->current();
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
    public function accept(Member $member): array
    {
        $current = $this->current();

        MemberAgreementAcceptance::query()->firstOrCreate(
            ['member_id' => $member->id, 'version' => $current['version']],
            ['accepted_at' => now()],
        );

        return $this->statusFor($member);
    }

    /** Админ: обновить текст соглашения → инкремент версии (требует повторного акцепта). */
    public function updateContent(string $text): array
    {
        $version = $this->current()['version'] + 1;
        PlanSetting::put(self::KEY, ['version' => $version, 'text' => $text]);

        return ['version' => $version, 'text' => $text];
    }

    /** Админ-сводка: текущая версия/текст + сколько участников приняли актуальную версию. */
    public function adminSummary(): array
    {
        $current = $this->current();
        $acceptedCount = MemberAgreementAcceptance::query()
            ->where('version', $current['version'])
            ->distinct('member_id')
            ->count('member_id');

        return [
            'version' => $current['version'],
            'text' => $current['text'],
            'accepted_current_count' => $acceptedCount,
            'members_total' => Member::query()->count(),
        ];
    }
}
