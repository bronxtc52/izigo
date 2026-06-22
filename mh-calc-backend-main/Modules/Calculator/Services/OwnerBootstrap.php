<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Role;

/**
 * Идемпотентно выдаёт роль owner участнику, чей telegram_id перечислен в
 * config('calculator.owner_telegram_ids') (источник — Key Vault, env OWNER_TELEGRAM_IDS).
 * Единая точка бутстрапа владельца — используется и Mini App ({@see \Modules\Calculator\Http\Middleware\ResolveTelegramMember}),
 * и веб-логином ({@see \Modules\Calculator\Http\Controllers\AuthController}).
 */
class OwnerBootstrap
{
    public function ensure(Member $member): void
    {
        if (!$this->isOwner((int) $member->telegram_id)) {
            return;
        }

        $role = Role::query()->where('name', 'owner')->first();
        if ($role !== null) {
            $member->roles()->syncWithoutDetaching([$role->id => ['leader_scope_member_id' => null]]);
        }
    }

    /** Указан ли telegram_id в конфиге владельцев (OWNER_TELEGRAM_IDS). */
    public function isOwner(int $telegramId): bool
    {
        $ids = array_filter(array_map('trim', explode(',', (string) config('calculator.owner_telegram_ids', ''))));

        return in_array((string) $telegramId, $ids, true);
    }
}
