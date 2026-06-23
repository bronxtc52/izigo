<?php

namespace Modules\Calculator\Services\Telegram;

use RuntimeException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Services\LeadService;

/**
 * Авторизация Mini App по initData: валидирует подпись и резолвит идентичность по
 * telegram_id. Три исхода: уже Member (участник в дереве) → member; ещё не купил →
 * lead (создаётся/перепривязывается по start_param реф-ссылки); валидный Telegram-юзер
 * без спонсора и без записи → none (нужна реф-ссылка). Member создаётся НЕ здесь, а при
 * первой подтверждённой оплате (промоушн лида), поэтому первый заход = лид, а не участник.
 */
class MiniAppAuth
{
    public function __construct(private readonly LeadService $leads)
    {
    }

    /**
     * @return array{type:'member',member:Member}|array{type:'lead',lead:\Modules\Calculator\Models\Lead}|array{type:'none'}
     */
    public function resolveIdentity(string $initData): array
    {
        $data = $this->validate($initData);
        $tgId = (int) $data['user']['id'];

        $member = Member::query()->where('telegram_id', $tgId)->first();
        if ($member !== null) {
            // Участник уже в дереве — спонсор замкнут, start_param игнорируется.
            return ['type' => 'member', 'member' => $member];
        }

        $user = $data['user'];
        $lead = $this->leads->attachOrReattach(
            $tgId,
            $this->displayName($user),
            $user['username'] ?? null,
            $data['start_param'] ?? null,
            $user['language_code'] ?? null,
        );

        return $lead !== null
            ? ['type' => 'lead', 'lead' => $lead]
            : ['type' => 'none'];
    }

    private function validate(string $initData): array
    {
        $token = (string) config('calculator.telegram_bot_token', '');
        $maxAge = (int) config('calculator.telegram_initdata_max_age', 86400);

        $data = TelegramInitData::validate($initData, $token, $maxAge);
        if ($data === null || empty($data['user']['id'])) {
            throw new RuntimeException('Невалидный initData');
        }

        return $data;
    }

    private function displayName(array $user): ?string
    {
        return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))
            ?: ($user['username'] ?? null);
    }
}
