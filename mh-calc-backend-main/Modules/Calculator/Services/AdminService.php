<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\PlanSetting;
use Modules\Calculator\Models\Role;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Services\Pii\ExportService;
use Modules\Calculator\Services\Pii\PiiService;

/**
 * Админ-операции: участники (поиск/фильтр + охват лидера), карточка, назначение
 * ролей, настройка плана. RBAC-гейты — на уровне маршрутов (calculator.role).
 */
class AdminService
{
    public function __construct(
        private readonly CabinetService $cabinet,
        private readonly EloquentPlanRepository $planRepository,
        private readonly PiiService $pii,
        private readonly ExportService $export,
    ) {
    }

    /** Список участников с фильтрами; лидер (не owner) видит только своё поддерево. */
    public function listMembers(Member $viewer, array $filters): array
    {
        $query = Member::query()->orderBy('id');

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
                    ->orWhere('telegram_username', 'ilike', $term);
            });
        }

        $this->applyLeaderScope($query, $viewer);

        $ranks = $this->rankAliasMap();
        $packages = $this->packageNameMap();
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (Member $m) => $this->rowOf($m, $ranks, $packages))->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
        ];
    }

    public function getMember(Member $viewer, int $id): array
    {
        $member = Member::query()->findOrFail($id);
        $this->assertVisible($viewer, $member);

        $ranks = $this->rankAliasMap();
        $packages = $this->packageNameMap();

        // C5-маскирование PII в карточке участника: полные telegram_username/ref_code видит
        // только owner. Для finance/support/leader маскируем (та же маска, что на /pii и /export),
        // иначе список/карточка участников обходили бы reveal-путь (G1). Reveal — отдельный
        // owner-only маршрут /members/{id}/pii/reveal с аудитом, его не трогаем.
        $maskPii = !$viewer->isOwner();

        // payout_details (TON-адрес) и kyc_status живут НЕ на Member (заявка вывода/KYC-запись),
        // а собираются ExportService — тем же коллектором, что кормит /pii и /export. Берём его,
        // чтобы источник и формат маски в карточке не разошлись с выделенными PII-эндпоинтами.
        // Маскирование живёт в сервисе (не за feature.flag c5_pii_export): маршрут /members/{id}
        // не гейтится флагом, поэтому не-owner видит маску всегда, owner — сырые значения.
        $piiCollected = $this->export->collect($member->id, $maskPii);

        return [
            'member' => $this->rowOf($member, $ranks, $packages) + [
                'parent_id' => $member->parent_id,
                'position' => $member->position,
                'ref_code' => $maskPii ? $this->pii->mask($member->ref_code, PiiService::TYPE_KYC) : $member->ref_code,
                'telegram_username' => $maskPii
                    ? $this->pii->mask($member->telegram_username, PiiService::TYPE_USERNAME)
                    : $member->telegram_username,
                'payout_details' => $piiCollected['payout_details'],
                'kyc_status' => $piiCollected['kyc_status'],
                'roles' => $member->roles()->pluck('name')->all(),
            ],
            'branch' => $this->cabinet->teamTree($member),
        ];
    }

    /** Назначить роль участнику. Только owner (гейт на маршруте). */
    public function assignRole(int $memberId, string $roleName, ?int $leaderScopeMemberId = null): array
    {
        $member = Member::query()->findOrFail($memberId);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        // Охват имеет смысл только у лидера; для прочих ролей всегда null.
        $scope = $roleName === 'leader' ? $leaderScopeMemberId : null;

        $member->roles()->syncWithoutDetaching([
            $role->id => ['leader_scope_member_id' => $scope],
        ]);

        return ['roles' => $member->roles()->pluck('name')->all()];
    }

    public function revokeRole(int $memberId, string $roleName): array
    {
        $member = Member::query()->findOrFail($memberId);
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $member->roles()->detach($role->id);

        return ['roles' => $member->roles()->pluck('name')->all()];
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

    private function rowOf(Member $m, array $ranks, array $packages = []): array
    {
        return [
            'id' => $m->id,
            'name' => $m->name,
            'status' => $m->status,
            'rank' => $m->rank_id ? ($ranks[$m->rank_id] ?? null) : null,
            // Имя пакета (как в отчёте «Пользователи»); package_id оставляем для совместимости.
            'package' => $m->package_id ? ($packages[$m->package_id] ?? "#{$m->package_id}") : null,
            'package_id' => $m->package_id,
            'sponsor_id' => $m->sponsor_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    private function rankAliasMap(): array
    {
        return DB::table('calculator_ranks')->pluck('alias', 'id')->all();
    }

    /** id пакета → отображаемое имя (accessor name локализован, потому через get()). */
    private function packageNameMap(): array
    {
        return Package::query()->get()->mapWithKeys(fn (Package $p) => [$p->id => $p->name])->all();
    }

    /** Ограничить выборку поддеревом лидера (если viewer — лидер и не owner). */
    private function applyLeaderScope($query, Member $viewer): void
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

    private function assertVisible(Member $viewer, Member $member): void
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
