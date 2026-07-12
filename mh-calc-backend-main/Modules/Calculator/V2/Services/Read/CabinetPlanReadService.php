<?php

namespace Modules\Calculator\V2\Services\Read;

use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Services\Status\StatusReadService;

/**
 * mh-full-plan T14: агрегатор шапки таба «Мой план» Mini App — ранг, тир и балансы
 * трёх счетов одним вызовом. Композиция из тех же read-сервисов, что кормят детальные
 * эндпоинты (StatusReadService + AccountsReadService) — второй правды о цифрах нет.
 */
class CabinetPlanReadService
{
    public function __construct(
        private readonly StatusReadService $status,
        private readonly AccountsReadService $accounts,
        private readonly PolicyVersionResolver $policyResolver,
    ) {
    }

    public function overview(int $memberId): array
    {
        $state = $this->status->currentState($memberId);
        $accounts = $this->accounts->accounts($memberId);
        $currentOrdinal = $state?->current_rank_code === null
            ? -1
            : StatusCode::from($state->current_rank_code)->ordinal();

        return [
            'state' => $state?->state ?? PartnerState::STATE_NONE,
            'rank_code' => $state?->current_rank_code,
            'rank_ordinal' => $currentOrdinal >= 0 ? $currentOrdinal : null,
            'next_rank_code' => $this->nextRankCode($currentOrdinal),
            'tier_code' => $state?->current_tier,
            'personal_pv' => $state?->personal_pv_total ?? '0',
            'grace_expires_at' => $state?->grace_expires_at?->toIso8601String(),
            // Балансы — те же, что отдаёт /accounts (интегер-центы + decimal).
            'accounts' => [
                'os_available_cents' => $accounts['os_available_cents'],
                'os_available' => $accounts['os_available'],
                'ns_cents' => $accounts['ns_cents'],
                'ns' => $accounts['ns'],
                'bs_available_cents' => $accounts['bs_available_cents'],
                'bs_available' => $accounts['bs_available'],
                'currency' => $accounts['currency'],
            ],
        ];
    }

    private function nextRankCode(int $currentOrdinal): ?string
    {
        try {
            $statuses = $this->policyResolver->current()->statuses();
        } catch (\Throwable) {
            return null;
        }
        foreach ($statuses as $status) {
            if ($status->ordinal === $currentOrdinal + 1) {
                return $status->code->value;
            }
        }

        return null;
    }
}
