<?php

namespace Modules\Calculator\V2\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Calculator\Models\Member;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Services\Read\AccountsReadService;
use Modules\Calculator\V2\Services\Read\AwardsReadService;
use Modules\Calculator\V2\Services\Read\CabinetPlanReadService;
use Modules\Calculator\V2\Services\Read\RankProgressReadService;

/**
 * mh-full-plan T14: единый read-контроллер таба «Мой план» Mini App (счета ОС/НС/БС,
 * прогресс 12 статусов, тир, награды). Тонкий — вся логика в read-сервисах T14.
 *
 * Гейт: telegram.auth + feature.flag:mh_plan_v2_miniapp (единый флаг UI, НЕ движковый
 * cutover-флаг T15). member-only: лид/не-member → 404 (данных плана у него нет). IDOR:
 * данные строго аутентифицированного участника (id из auth-атрибута, из запроса не
 * принимается). Read-only — ни одной записи в ledger.
 */
class CabinetPlanController
{
    public function __construct(
        private readonly CabinetPlanReadService $plan,
        private readonly RankProgressReadService $rankProgress,
        private readonly AccountsReadService $accounts,
        private readonly AwardsReadService $awards,
        private readonly PolicyVersionResolver $policyResolver,
    ) {
    }

    /** Шапка: ранг + тир + балансы трёх счетов одним DTO. */
    public function overview(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->plan->overview($member->id));
    }

    /** Лестница 12 статусов, достигнутые + разбор следующего (3 варианта), тир. */
    public function rankProgress(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->rankProgress->progress($member->id, $this->policyResolver->current()));
    }

    /** Балансы счетов + ближайшие сгорания + лимит оплаты с ОС + дата перевода НС→ОС. */
    public function accounts(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->accounts->accounts($member->id));
    }

    /** Активные лоты субсчёта (earliest-expiry-first). account ∈ {os,ns,bs} → иначе 422. */
    public function lots(Request $request, string $account): JsonResponse
    {
        $member = $this->member($request);
        $this->assertAccount($account);

        return $this->guarded(fn () => $this->accounts->lots($member->id, $account));
    }

    /** Лента движений субсчёта, cursor-пагинация. account ∈ {os,ns,bs} → иначе 422. */
    public function history(Request $request, string $account): JsonResponse
    {
        $member = $this->member($request);
        $this->assertAccount($account);
        $cursor = $request->query('cursor');
        $cursor = is_numeric($cursor) ? (int) $cursor : null;
        $limit = (int) $request->query('limit', 20);

        return $this->guarded(fn () => $this->accounts->history($member->id, $account, $cursor, $limit));
    }

    /** Каталог наград из PolicyVersion + состояние по entitlement'ам T10 / рангам. */
    public function awards(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return $this->guarded(fn () => $this->awards->awards($member->id, $this->policyResolver->current()));
    }

    // ------------------------------------------------------------------

    private function assertAccount(string $account): void
    {
        if (! in_array($account, AccountsReadService::ACCOUNTS, true)) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'status' => 'error',
                'message' => 'Неизвестный субсчёт: ' . $account,
            ], 422));
        }
    }

    private function member(Request $request): Member
    {
        /** @var ?Member $member */
        $member = $request->attributes->get('member');
        if ($member === null) {
            // Лид (валидный initData, но не участник) — плана нет: 404 (не раскрываем).
            throw new ModelNotFoundException();
        }

        return $member;
    }

    private function guarded(callable $fn): JsonResponse
    {
        try {
            return response()->json(['status' => 'success', 'data' => $fn()]);
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Не найдено'], 404);
        } catch (\Modules\Calculator\V2\Services\PolicyNotActiveException $e) {
            // Политика ещё не активирована — план недоступен, но не 500.
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 409);
        } catch (\DomainException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }
}
