<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Facades\CalculatorAuth;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use RuntimeException;

/**
 * Данные кабинета партнёра: профиль/реф-ссылка, доход (разбивка + логика),
 * прогресс рангов, дерево команды. Активация пакета делегируется ActivationService.
 */
class CabinetService
{
    private const TREE_MAX_DEPTH = 6;

    public function __construct(
        private readonly EloquentNetworkRepository $networkRepository,
        private readonly EloquentPlanRepository $planRepository,
        private readonly ActivationService $activation,
    ) {
    }

    public function currentMember(): Member
    {
        $token = CalculatorAuth::token();
        $member = $token
            ? Member::query()->where('calculator_user_id', $token->calculator_user_id)->first()
            : null;

        if ($member === null) {
            throw new RuntimeException('Участник не найден для текущего пользователя');
        }

        return $member;
    }

    public function profile(Member $member): array
    {
        $plan = $this->planRepository->load();
        $rank = $this->rankById($plan, $member->rank_id);

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'ref_code' => $member->ref_code,
                'status' => $member->status,
                'package_id' => $member->package_id,
                'rank' => $rank ? ['id' => $rank->id, 'alias' => $rank->alias] : null,
            ],
            'ref_link' => $this->refLink($member->ref_code),
        ];
    }

    public function dashboard(Member $member): array
    {
        $earning = MemberEarning::query()->where('member_id', $member->id)->first();
        $lines = MemberBonusLine::query()
            ->where('recipient_member_id', $member->id)
            ->orderByDesc('id')
            ->get(['type', 'amount', 'basis', 'calculated_at']);

        return [
            'total' => $earning?->total ?? '0.00',
            'by_type' => $earning?->by_type ?? [],
            'lines' => $lines->map(static fn (MemberBonusLine $l) => [
                'type' => $l->type,
                'amount' => $l->amount,
                'basis' => $l->basis,           // «логика расчёта»: уровень/источник/мета
                'calculated_at' => $l->calculated_at?->toIso8601String(),
            ])->all(),
        ];
    }

    public function rankProgress(Member $member): array
    {
        $plan = $this->planRepository->load();
        $network = $this->networkRepository->load();
        $node = $network->get($member->id);

        $current = $this->rankById($plan, $member->rank_id);
        $next = $this->nextRank($plan, $current?->sort ?? 0);

        $smallBranchPv = $node ? $this->smallBranchPv($node, $plan) : 0;
        // ПРИМ.: упрощённый индикатор — все лично приглашённые по сети. Доменная
        // квалификация ранга (RankSnapshot) считает в placement-поддереве с темпоральной
        // отсечкой; для точного прогресс-бара переиспользовать RankSnapshot (S3-долг).
        $personalCount = Member::query()->where('sponsor_id', $member->id)->count();

        return [
            'current' => $current ? ['id' => $current->id, 'alias' => $current->alias] : null,
            'next' => $next ? [
                'id' => $next->id,
                'alias' => $next->alias,
                'conditions' => [
                    'small_branch_pv' => $next->smallBranchVolume->units(),
                    'personal_count' => $next->personalCount,
                    'personal_in_rank_count' => $next->personalInRankCount,
                    'personal_in_rank_id' => $next->personalInRankId,
                ],
            ] : null,
            'progress' => [
                'small_branch_pv' => $smallBranchPv / 100,
                'personal_count' => $personalCount,
            ],
        ];
    }

    public function teamTree(Member $member): array
    {
        return $this->buildNode($member, 0);
    }

    public function activatePackage(Member $member, int $packageId, ?string $idempotencyKey = null): array
    {
        $key = $idempotencyKey ?: "activate:m{$member->id}:p{$packageId}";
        $event = $this->activation->activate($member->id, $packageId, $key);

        return [
            'event_id' => $event->id,
            'status' => $event->status,
            'member' => $this->profile($member->refresh())['member'],
        ];
    }

    /** Узел дерева команды в формате, удобном для react-d3-tree. */
    private function buildNode(Member $member, int $depth): array
    {
        $node = [
            'name' => $member->name ?? ('#' . $member->id),
            'attributes' => [
                'id' => $member->id,
                'status' => $member->status,
                'package_id' => $member->package_id,
                'position' => $member->position,
            ],
            'children' => [],
        ];

        if ($depth >= self::TREE_MAX_DEPTH) {
            return $node;
        }

        $children = Member::query()
            ->where('parent_id', $member->id)
            ->orderByRaw("CASE position WHEN 'left' THEN 0 ELSE 1 END")
            ->get();

        foreach ($children as $child) {
            $node['children'][] = $this->buildNode($child, $depth + 1);
        }

        return $node;
    }

    /** Объём малой ветки (сотые PV): min по двум placement-ногам, отсутствующая = 0. */
    private function smallBranchPv(MemberNode $node, Plan $plan): int
    {
        $legs = [];
        foreach ($node->children as $child) {
            $legs[] = $this->subtreePvHundredths($child, $plan);
        }
        while (count($legs) < 2) {
            $legs[] = 0;
        }

        return min($legs);
    }

    private function subtreePvHundredths(MemberNode $node, Plan $plan): int
    {
        $package = $plan->package($node->packageId);
        $sum = $package?->pv->hundredths ?? 0;
        foreach ($node->children as $child) {
            $sum += $this->subtreePvHundredths($child, $plan);
        }

        return $sum;
    }

    private function rankById(Plan $plan, ?int $rankId)
    {
        if ($rankId === null) {
            return null;
        }
        foreach ($plan->ranksOrdered() as $rank) {
            if ($rank->id === $rankId) {
                return $rank;
            }
        }

        return null;
    }

    private function nextRank(Plan $plan, int $currentSort)
    {
        foreach ($plan->ranksOrdered() as $rank) {
            if ($rank->sort > $currentSort) {
                return $rank;
            }
        }

        return null;
    }

    private function refLink(string $refCode): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return "{$base}/?ref={$refCode}";
    }
}
