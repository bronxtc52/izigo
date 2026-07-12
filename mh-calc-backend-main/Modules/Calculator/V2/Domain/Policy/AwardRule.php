<?php

namespace Modules\Calculator\V2\Domain\Policy;

/**
 * T01: квалификационные награды (T10): единоразовые суммы USD-центами на БС
 * (destination BS), при скачке через ранги — все пройденные (on_rank_jump ALL_CROSSED,
 * DEC-040); VP — 3 транша по 50 000 USD (этапы 2-3 — по квалификациям глобального
 * бонуса, DEC-042). Выплата вручную by design.
 */
final class AwardRule
{
    /**
     * @param array<string, int> $byStatusCents ключ — код статуса MANAGER..DIAMOND_DIRECTOR
     * @param array<int, array{sequence: int, amount_cents: int, trigger: string}> $vpTranches
     */
    public function __construct(
        public readonly string $destination,
        public readonly string $onRankJump,
        public readonly array $byStatusCents,
        public readonly array $vpTranches,
    ) {
    }
}
