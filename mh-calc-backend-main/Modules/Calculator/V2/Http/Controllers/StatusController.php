<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Services\Status\StatusReadService;

/**
 * T05: cabinet-API статуса (Mini App, middleware telegram.auth + feature.flag:
 * mh_v2_statuses). Участник видит ТОЛЬКО СВОЙ статус/прогресс/тир/историю
 * (member берётся из request-атрибута аутентификации, chosen id клиента
 * игнорируется — защита от IDOR). Read-only.
 */
class StatusController
{
    public function __construct(
        private readonly StatusReadService $reader,
        private readonly PolicyVersionResolver $policyResolver,
    ) {
    }

    /** Мой статус: state, ранг, тир, personal PV, grace-дедлайн. */
    public function me(Request $request): JsonResponse
    {
        $member = $this->authMember($request);
        $state = $this->reader->currentState($member->id);
        $policy = $this->policyResolver->current();

        return response()->json(['status' => 'success', 'data' => [
            'state' => $state?->state ?? 'none',
            'current_rank_code' => $state?->current_rank_code,
            'current_tier' => $state?->current_tier,
            'personal_pv_total' => $state?->personal_pv_total ?? '0',
            'grace_expires_at' => $state?->grace_expires_at,
            'grace_outcome' => $state?->grace_outcome,
            'progress' => $this->reader->nextStatusProgress($member->id, $policy->statuses()),
        ]]);
    }

    /** Мои пройденные ранги (для «все достигнутые награды» T10 / истории). */
    public function ranks(Request $request): JsonResponse
    {
        $member = $this->authMember($request);

        return response()->json([
            'status' => 'success',
            'data' => $this->reader->achievedRanks($member->id),
        ]);
    }

    private function authMember(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        abort_if($member === null, 403, 'Требуется активированный участник');

        return $member;
    }
}
