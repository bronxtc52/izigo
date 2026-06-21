<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\Placement\PlacementService;

/**
 * Создание реального участника и постановка его в сеть. Используется при
 * регистрации (по реф-ссылке) и сидировании.
 */
class MemberService
{
    public function __construct(private readonly PlacementService $placement)
    {
    }

    /**
     * Регистрация участника через Telegram — единственный способ. Идентичность —
     * telegram_id. Спонсор — из start_param реф-ссылки. Атомарно create+place
     * (при сбое размещения не остаётся «фантомного» участника без места в дереве).
     */
    public function registerTelegram(
        int $telegramId,
        ?string $name,
        ?string $username,
        ?string $sponsorRef = null,
        ?string $language = null,
        ?string $parentRef = null,
        ?string $position = null,
    ): Member {
        return DB::transaction(function () use ($telegramId, $name, $username, $sponsorRef, $language, $parentRef, $position) {
            $sponsor = $this->resolveByRef($sponsorRef);
            // parent/position — для ручного режима размещения (manual); в авто-режиме
            // (Telegram self-registration) остаются null и PlacementService спилловерит сам.
            $parent = $this->resolveByRef($parentRef);

            // Несохранённый участник — PlacementService вставит его сразу с родителем,
            // чтобы не было транзиентного parent_id=NULL (он нарушил бы индекс корня).
            $member = new Member([
                'name' => $name ?: ('tg:' . $telegramId),
                'ref_code' => $this->uniqueRefCode(),
                'telegram_id' => $telegramId,
                'telegram_username' => $username,
                'language' => $language,
                'status' => 'registered',
            ]);

            return $this->placement->place($member, $sponsor, $parent?->id, $position);
        });
    }

    public function resolveByRef(?string $refCode): ?Member
    {
        return $refCode ? Member::query()->where('ref_code', $refCode)->first() : null;
    }

    private function uniqueRefCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Member::query()->where('ref_code', $code)->exists());

        return $code;
    }
}
