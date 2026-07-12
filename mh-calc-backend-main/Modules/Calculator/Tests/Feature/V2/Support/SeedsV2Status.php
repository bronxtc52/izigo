<?php

namespace Modules\Calculator\Tests\Feature\V2\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\PartnerState;
use Modules\Calculator\V2\Services\DefaultPolicyConfig;
use Modules\Calculator\V2\Services\PolicyVersionService;

/**
 * Хелперы feature-тестов T05: активная политика (реальный DefaultPolicyConfig),
 * сид partner_state/rank_history и вызов сервисов под advisory-lock активаций.
 */
trait SeedsV2Status
{
    /** Активировать каноническую политику MH V2 (retro с 2026-01-01). */
    protected function activateV2Policy(): PolicyV2
    {
        $service = app(PolicyVersionService::class);
        $draft = $service->createDraft('mh-v2-usd-t05-test', DefaultPolicyConfig::doc(), null);
        $service->activate($draft->id, null, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), allowRetro: true);

        return app(PolicyVersionResolver::class)->current();
    }

    protected function policy(): PolicyV2
    {
        return app(PolicyVersionResolver::class)->current();
    }

    /** Выполнить callback внутри транзакции с advisory-lock активаций (как оркестратор). */
    protected function underActivationLock(callable $fn): mixed
    {
        return DB::transaction(function () use ($fn) {
            app(ActivationService::class)->acquireActivationLock();

            return $fn();
        });
    }

    protected function seedPartnerState(int $memberId, string $state, ?StatusCode $rank = null, ?CarbonImmutable $clientAt = null): PartnerState
    {
        return PartnerState::query()->updateOrCreate(
            ['member_id' => $memberId],
            [
                'state' => $state,
                'current_rank_code' => $rank?->value,
                'personal_pv_total' => '0',
                'client_achieved_at' => $clientAt,
            ],
        );
    }

    protected function seedRank(int $memberId, StatusCode $rank, ?CarbonImmutable $at = null): void
    {
        DB::table('v2_rank_history')->insertOrIgnore([
            'member_id' => $memberId,
            'rank_code' => $rank->value,
            'rank_ordinal' => $rank->ordinal(),
            'achieved_at' => $at ?? CarbonImmutable::parse('2026-02-01', 'UTC'),
            'evaluation_id' => null,
            'policy_version_id' => 1,
            'created_at' => now(),
        ]);
    }
}
