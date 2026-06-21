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
     * Создать участника для пользователя и разместить в дереве.
     * Спонсор/родитель задаются реф-кодами (ref_code).
     */
    public function register(
        int $userId,
        ?string $name,
        ?string $sponsorRef = null,
        ?string $parentRef = null,
        ?string $position = null,
    ): Member {
        // Создание и размещение — атомарно: при сбое размещения не остаётся
        // «фантомного» участника без места в дереве (второй корень).
        return DB::transaction(function () use ($userId, $name, $sponsorRef, $parentRef, $position) {
            $sponsor = $this->resolveByRef($sponsorRef);
            $parent = $this->resolveByRef($parentRef);

            // Несохранённый участник — PlacementService вставит его сразу с родителем,
            // чтобы не было транзиентного parent_id=NULL (он нарушил бы индекс корня).
            $member = new Member([
                'calculator_user_id' => $userId,
                'name' => $name,
                'ref_code' => $this->uniqueRefCode(),
                'status' => 'registered',
            ]);

            return $this->placement->place(
                $member,
                $sponsor,
                $parent?->id,
                $position,
            );
        });
    }

    /**
     * Регистрация участника через Telegram (без email-аккаунта): идентичность —
     * telegram_id. Спонсор — из start_param реф-ссылки. Атомарно create+place.
     */
    public function registerTelegram(
        int $telegramId,
        ?string $name,
        ?string $username,
        ?string $sponsorRef = null,
    ): Member {
        return DB::transaction(function () use ($telegramId, $name, $username, $sponsorRef) {
            $sponsor = $this->resolveByRef($sponsorRef);

            $member = new Member([
                'name' => $name ?: ('tg:' . $telegramId),
                'ref_code' => $this->uniqueRefCode(),
                'telegram_id' => $telegramId,
                'telegram_username' => $username,
                'status' => 'registered',
            ]);

            return $this->placement->place($member, $sponsor);
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
