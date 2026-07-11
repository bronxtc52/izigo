<?php

namespace Modules\Calculator\V2\Domain\Rank;

use Modules\Calculator\V2\Domain\Policy\QualificationVariantRule;
use Modules\Calculator\V2\Domain\Policy\StatusRule;

/**
 * T05: детерминированное назначение кандидатов на слоты варианта квалификации
 * (BR-RANK-001 + DEC-022..024):
 *  - anchor-слоты — ТОЛЬКО кандидаты 1-й линии; support — 1-я линия или глубина;
 *  - компаратор per-variant: exact (ровно ранг) | at_least («и выше», дефолт Гейта A);
 *  - кандидат не используется дважды (anchor не закрывает support-слот);
 *  - при distinct_root_branches все слоты — из ПОПАРНО РАЗНЫХ корневых ветвей
 *    (одна ветвь = один слот; два лидера в одной ветви — пример Директора PPTX:S38);
 *  - детерминизм: сортировка (ранг desc, member_id asc); перестановка входа
 *    не меняет результат.
 */
class DistinctBranchAssigner
{
    /**
     * @param RankCandidate[] $candidates
     */
    public function assign(StatusRule $target, QualificationVariantRule $variant, array $candidates): ?RankAssignment
    {
        $anchorOrdinal = $target->anchorRank?->ordinal();
        $supportOrdinal = $target->supportRank?->ordinal();

        $matches = function (RankCandidate $c, ?int $required) use ($variant): bool {
            if ($required === null) {
                return false;
            }

            return $variant->comparator === QualificationVariantRule::COMPARATOR_EXACT
                ? $c->rankOrdinal === $required
                : $c->rankOrdinal >= $required;
        };

        $sorted = $this->sortCandidates($candidates);
        $anchorEligible = array_values(array_filter(
            $sorted,
            fn (RankCandidate $c) => $c->isLevelOne && $matches($c, $anchorOrdinal)
        ));
        $supportEligible = array_values(array_filter(
            $sorted,
            fn (RankCandidate $c) => $matches($c, $supportOrdinal)
        ));

        return $variant->distinctRootBranches
            ? $this->assignDistinctBranches($variant, $anchorEligible, $supportEligible)
            : $this->assignPlain($variant, $anchorEligible, $supportEligible);
    }

    /**
     * Без требования различных ветвей (вариант 1): anchor-слоты — разные L1-кандидаты
     * (каждый L1-узел сам по себе отдельная корневая ветвь), support — без повторного
     * использования кандидатов.
     *
     * @param RankCandidate[] $anchorEligible
     * @param RankCandidate[] $supportEligible
     */
    private function assignPlain(QualificationVariantRule $variant, array $anchorEligible, array $supportEligible): ?RankAssignment
    {
        if (count($anchorEligible) < $variant->anchorCount) {
            return null;
        }

        $slots = [];
        $used = [];
        foreach (array_slice($anchorEligible, 0, $variant->anchorCount) as $c) {
            $slots[] = $this->slot($c, RankAssignment::SLOT_ANCHOR);
            $used[$c->memberId] = true;
        }

        $supports = array_values(array_filter($supportEligible, fn (RankCandidate $c) => !isset($used[$c->memberId])));
        if (count($supports) < $variant->supportCount) {
            return null;
        }
        foreach (array_slice($supports, 0, $variant->supportCount) as $c) {
            $slots[] = $this->slot($c, RankAssignment::SLOT_SUPPORT);
        }

        return new RankAssignment($slots);
    }

    /**
     * distinct_root_branches (варианты 2-3, DEC-023): одна корневая ветвь закрывает
     * максимум ОДИН слот. Ветвь представляется лучшим кандидатом на роль; anchor-ветви
     * выбираются перебором (детерминированный порядок, счётчики малы: 0..2), support —
     * жадно из оставшихся ветвей.
     *
     * @param RankCandidate[] $anchorEligible
     * @param RankCandidate[] $supportEligible
     */
    private function assignDistinctBranches(QualificationVariantRule $variant, array $anchorEligible, array $supportEligible): ?RankAssignment
    {
        // Лучший кандидат ветви на каждую роль (списки уже отсортированы детерминированно).
        $anchorByBranch = [];
        foreach ($anchorEligible as $c) {
            $anchorByBranch[$c->rootBranchMemberId] ??= $c;
        }
        $supportByBranch = [];
        foreach ($supportEligible as $c) {
            $supportByBranch[$c->rootBranchMemberId] ??= $c;
        }

        $anchorBranches = $this->sortBranches($anchorByBranch);
        $supportBranches = $this->sortBranches($supportByBranch);

        return $this->pickAnchors($variant, $anchorBranches, $supportBranches, []);
    }

    /**
     * Рекурсивный перебор anchor-ветвей (глубина = anchor_count <= 2), затем жадное
     * заполнение support-слотов оставшимися ветвями. Первый найденный вариант в
     * детерминированном порядке и возвращается.
     *
     * @param array<int, RankCandidate> $anchorBranches branchId => кандидат (упорядочено)
     * @param array<int, RankCandidate> $supportBranches branchId => кандидат (упорядочено)
     * @param array<int, RankCandidate> $chosenAnchors branchId => кандидат
     */
    private function pickAnchors(
        QualificationVariantRule $variant,
        array $anchorBranches,
        array $supportBranches,
        array $chosenAnchors,
    ): ?RankAssignment {
        if (count($chosenAnchors) === $variant->anchorCount) {
            $supports = array_diff_key($supportBranches, $chosenAnchors);
            if (count($supports) < $variant->supportCount) {
                return null;
            }

            $slots = [];
            foreach ($chosenAnchors as $c) {
                $slots[] = $this->slot($c, RankAssignment::SLOT_ANCHOR);
            }
            foreach (array_slice($supports, 0, $variant->supportCount, true) as $c) {
                $slots[] = $this->slot($c, RankAssignment::SLOT_SUPPORT);
            }

            return new RankAssignment($slots);
        }

        foreach ($anchorBranches as $branchId => $candidate) {
            if (isset($chosenAnchors[$branchId])) {
                continue;
            }
            $result = $this->pickAnchors(
                $variant,
                $anchorBranches,
                $supportBranches,
                $chosenAnchors + [$branchId => $candidate],
            );
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @param RankCandidate[] $candidates */
    private function sortCandidates(array $candidates): array
    {
        $sorted = array_values($candidates);
        usort($sorted, static fn (RankCandidate $a, RankCandidate $b) =>
            [$b->rankOrdinal, $a->memberId] <=> [$a->rankOrdinal, $b->memberId]);

        return $sorted;
    }

    /**
     * @param array<int, RankCandidate> $byBranch
     * @return array<int, RankCandidate> branchId => кандидат, детерминированный порядок
     */
    private function sortBranches(array $byBranch): array
    {
        uksort($byBranch, static fn (int $a, int $b) =>
            [$byBranch[$b]->rankOrdinal, $a] <=> [$byBranch[$a]->rankOrdinal, $b]);

        return $byBranch;
    }

    /** @return array{qualifier_partner_id:int, root_branch_member_id:int, rank_code_as_of:string, slot:string} */
    private function slot(RankCandidate $c, string $slot): array
    {
        return [
            'qualifier_partner_id' => $c->memberId,
            'root_branch_member_id' => $c->rootBranchMemberId,
            'rank_code_as_of' => $c->rankCode,
            'slot' => $slot,
        ];
    }
}
