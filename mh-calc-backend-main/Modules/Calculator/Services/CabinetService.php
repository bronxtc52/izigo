<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Domain\Model\MemberNode;
use Modules\Calculator\Domain\Plan\Plan;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;

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
                // Выбранный язык интерфейса (персист), чтобы Mini App знал текущий язык.
                'language' => $member->language,
                'package_id' => $member->package_id,
                'rank' => $rank ? ['id' => $rank->id, 'alias' => $rank->alias] : null,
                // Личные рефералы (по sponsor_id, любая глубина бинара) — НЕ бинар-ноги.
                // Источник истины для счётчика «Личных»/«Приглашено» в Mini App.
                'personal_count' => Member::query()->where('sponsor_id', $member->id)->count(),
                // Роли — чтобы Mini App показал админ-раздел владельцу/админам.
                'roles' => $member->roles()->pluck('name')->all(),
            ],
            'ref_link' => $this->refLink($member->ref_code),
        ];
    }

    /**
     * Состояние лида (ещё не купил пакет) для Mini App: спонсор, срок привязки, окно.
     * У лида нет дерева/дохода/реф-ссылки — экран «активируйте пакет, чтобы вступить».
     */
    public function leadState(Lead $lead): array
    {
        $sponsor = $lead->sponsor;

        return [
            'is_lead' => true,
            'status' => 'lead',
            'sponsor' => $sponsor ? [
                'name' => $sponsor->name ?? ('#' . $sponsor->id),
                'ref_code' => $sponsor->ref_code,
            ] : null,
            'expires_at' => $lead->expires_at?->toIso8601String(),
            'window_days' => (int) config('calculator.lead_window_days', 7),
        ];
    }

    /**
     * Личные рефералы участника = все, у кого sponsor_id = я (зарегались и купили по моей
     * рефке), на ЛЮБОЙ глубине бинар-дерева. Это спонсорство, НЕ бинар-команда (та — дерево
     * placement, где сверху по спилловеру встают чужие). Глубина в бинаре показывается, чтобы
     * было видно: личный реферал может стоять глубоко.
     */
    public function personalReferrals(Member $member): array
    {
        $plan = $this->planRepository->load();
        $myDepth = $this->nlevel($member->path);

        return Member::query()
            ->where('sponsor_id', $member->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (Member $m) use ($plan, $myDepth) {
                $rank = $this->rankById($plan, $m->rank_id);
                $depth = $this->nlevel($m->path);

                return [
                    'id' => $m->id,
                    'name' => $m->name ?? ('#' . $m->id),
                    'status' => $m->status,
                    'rank' => $rank ? ['id' => $rank->id, 'alias' => $rank->alias] : null,
                    'position' => $m->position,
                    'binary_depth' => $depth,
                    // Глубина в МОЁМ поддереве (спилловер ставит личных под спонсора).
                    'depth_from_me' => ($myDepth !== null && $depth !== null)
                        ? max(0, $depth - $myDepth)
                        : null,
                    'created_at' => optional($m->created_at)->toIso8601String(),
                ];
            })->all();
    }

    /** Глубина узла в бинар-дереве из materialized path (ids через точку). */
    private function nlevel(?string $path): ?int
    {
        if ($path === null || $path === '') {
            return null;
        }

        return substr_count($path, '.') + 1;
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

    public function activatePackage(Member $member, int $packageId): array
    {
        // Ключ идемпотентности генерируется ТОЛЬКО сервером и живёт в собственном namespace
        // `activate:*`, не пересекаясь с системными ключами оплаченных заказов (`order:{id}`) и
        // autoship. Клиентский ключ не принимается (аудит B-2) — отравить чужой заказ снаружи нельзя.
        $key = "activate:m{$member->id}:p{$packageId}";
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

    /**
     * Реф-ссылка = Telegram deep-link на Mini App (платформа Telegram-only). Форма
     * t.me/<bot>/<short>?startapp=<ref_code> — единственная, что доставляет start_param
     * в initData (его ловит MiniAppAuth для привязки спонсора). Веб-ссылка `?ref=` не
     * работала бы. Фолбэк на веб — только если username бота не сконфигурирован.
     */
    private function refLink(string $refCode): string
    {
        $bot = trim((string) config('calculator.telegram_bot_username', ''));
        if ($bot === '') {
            $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

            return "{$base}/?ref={$refCode}";
        }

        $short = trim((string) config('calculator.telegram_miniapp_short_name', ''));
        $path = $short !== '' ? "/{$short}" : '';

        return "https://t.me/{$bot}{$path}?startapp={$refCode}";
    }
}
