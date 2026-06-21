<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\CalculatorUser;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Models\Role;
use Modules\Calculator\Repositories\EloquentPlanRepository;

/**
 * Админ-операции: участники (поиск/фильтр + охват лидера), карточка, назначение
 * ролей, настройка плана. RBAC-гейты — на уровне маршрутов (calculator.role).
 */
class AdminService
{
    public function __construct(
        private readonly CabinetService $cabinet,
        private readonly EloquentPlanRepository $planRepository,
    ) {
    }

    /** Список участников с фильтрами; лидер (не owner) видит только своё поддерево. */
    public function listMembers(CalculatorUser $viewer, array $filters): array
    {
        $query = Member::query()->with('user:id,email')->orderBy('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['rank_id'])) {
            $query->where('rank_id', (int) $filters['rank_id']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'ilike', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'ilike', $term));
            });
        }

        $this->applyLeaderScope($query, $viewer);

        $ranks = $this->rankAliasMap();
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (Member $m) => $this->rowOf($m, $ranks))->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
        ];
    }

    public function getMember(CalculatorUser $viewer, int $id): array
    {
        $member = Member::query()->with('user:id,email')->findOrFail($id);
        $this->assertVisible($viewer, $member);

        $ranks = $this->rankAliasMap();

        return [
            'member' => $this->rowOf($member, $ranks) + [
                'parent_id' => $member->parent_id,
                'position' => $member->position,
                'ref_code' => $member->ref_code,
                'email' => $member->user?->email,
                'roles' => $member->user ? $member->user->roles()->pluck('name')->all() : [],
            ],
            'branch' => $this->cabinet->teamTree($member),
        ];
    }

    /** Назначить роль пользователю участника. Только owner (гейт на маршруте). */
    public function assignRole(int $memberId, string $roleName, ?int $leaderScopeMemberId = null): array
    {
        $member = Member::query()->findOrFail($memberId);
        if ($member->calculator_user_id === null) {
            throw new InvalidArgumentException('У участника нет аккаунта пользователя');
        }
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        // Охват имеет смысл только у лидера; для прочих ролей всегда null.
        $scope = $roleName === 'leader' ? $leaderScopeMemberId : null;

        $user = CalculatorUser::query()->findOrFail($member->calculator_user_id);
        $user->roles()->syncWithoutDetaching([
            $role->id => ['leader_scope_member_id' => $scope],
        ]);

        return ['roles' => $user->roles()->pluck('name')->all()];
    }

    public function revokeRole(int $memberId, string $roleName): array
    {
        $member = Member::query()->findOrFail($memberId);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        if ($member->calculator_user_id !== null) {
            CalculatorUser::query()->find($member->calculator_user_id)?->roles()->detach($role->id);
        }
        $user = $member->calculator_user_id ? CalculatorUser::find($member->calculator_user_id) : null;

        return ['roles' => $user ? $user->roles()->pluck('name')->all() : []];
    }

    /** Текущие настройки плана + сводка процентов/порогов (read-only часть). */
    public function getPlanSettings(): array
    {
        $plan = $this->planRepository->load();

        return [
            'placement_mode' => PlanSetting::get('placement_mode', 'auto'),
            'rank_bonuses' => PlanSetting::get('rank_bonuses', []),
            'ranks' => array_map(static fn ($r) => [
                'id' => $r->id,
                'alias' => $r->alias,
                'small_branch_pv' => $r->smallBranchVolume->units(),
                'personal_count' => $r->personalCount,
            ], $plan->ranksOrdered()),
        ];
    }

    /** Обновить настройки плана. Только owner (гейт на маршруте). */
    public function updatePlanSettings(array $data): array
    {
        if (array_key_exists('placement_mode', $data)) {
            if (!in_array($data['placement_mode'], ['auto', 'manual'], true)) {
                throw new InvalidArgumentException('placement_mode: auto|manual');
            }
            PlanSetting::put('placement_mode', $data['placement_mode']);
        }
        if (array_key_exists('rank_bonuses', $data) && is_array($data['rank_bonuses'])) {
            PlanSetting::put('rank_bonuses', $data['rank_bonuses']);
        }

        return $this->getPlanSettings();
    }

    private function rowOf(Member $m, array $ranks): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'status' => $m->status,
            'rank' => $m->rank_id ? ($ranks[$m->rank_id] ?? null) : null,
            'package_id' => $m->package_id,
            'sponsor_id' => $m->sponsor_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function rankAliasMap(): array
    {
        return DB::table('calculator_ranks')->pluck('alias', 'id')->all();
    }

    /** Ограничить выборку поддеревом лидера (если viewer — лидер и не owner). */
    private function applyLeaderScope($query, CalculatorUser $viewer): void
    {
        if ($viewer->isOwner()) {
            return;
        }
        $scopeId = $viewer->leaderScopeMemberId();
        if ($scopeId === null) {
            return; // finance/support — видят всех (только чтение)
        }
        $scope = Member::find($scopeId);
        if ($scope === null) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('id', $this->descendantIds($scope));
    }

    private function assertVisible(CalculatorUser $viewer, Member $member): void
    {
        if ($viewer->isOwner()) {
            return;
        }
        $scopeId = $viewer->leaderScopeMemberId();
        if ($scopeId !== null && !in_array($member->id, $this->descendantIds(Member::find($scopeId)), true)) {
            throw new InvalidArgumentException('Участник вне вашего поддерева');
        }
    }

    /**
     * Id спонсорской команды лидера (включая его самого), BFS по sponsor_id.
     * Охват лидера = его ЛП-линия (кого он привёл), а НЕ placement-поддерево:
     * при авто-спилловере личник может уехать в чужую ногу по parent_id.
     */
    private function descendantIds(Member $root): array
    {
        $ids = [$root->id];
        $queue = [$root->id];
        while ($queue !== []) {
            $id = array_shift($queue);
            foreach (Member::query()->where('sponsor_id', $id)->pluck('id') as $childId) {
                $ids[] = (int) $childId;
                $queue[] = (int) $childId;
            }
        }

        return $ids;
    }
}
