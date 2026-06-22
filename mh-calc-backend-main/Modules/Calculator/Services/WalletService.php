<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;

/**
 * Чтение кошелька партнёра (Фаза 3): баланс из кэша member_wallets и лента движений
 * доступного баланса из ledger_entries. Source of truth — ledger; деньги — целые центы,
 * наружу отдаём строкой decimal "D.CC".
 */
class WalletService
{
    private const TX_LIMIT = 50;

    public function balance(Member $member): array
    {
        $w = MemberWallet::query()->where('member_id', $member->id)->first();

        return [
            'available' => $this->centsToDecimal($w->available_cents ?? 0),
            'held' => $this->centsToDecimal($w->held_cents ?? 0),
            'clawback_debt' => $this->centsToDecimal($w->clawback_debt_cents ?? 0),
            'currency' => $w->currency ?? 'USD',
        ];
    }

    /**
     * Лента движений доступного баланса (начисления +, холд под вывод −, возврат +).
     * Курсорная пагинация по id проводки (beforeId — отдать более старые).
     */
    public function transactions(Member $member, ?int $beforeId = null, int $limit = self::TX_LIMIT): array
    {
        $limit = max(1, min($limit, 100));

        $entries = LedgerEntry::query()
            ->where('member_id', $member->id)
            ->where('account_type', LedgerService::ACC_AVAILABLE)
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($limit + 1) // +1 чтобы определить наличие следующей страницы
            ->get(['id', 'direction', 'amount_cents', 'source_type', 'source_id', 'created_at']);

        $hasMore = $entries->count() > $limit;
        $page = $entries->take($limit);

        return [
            'items' => $page->map(fn (LedgerEntry $e) => [
                'id' => $e->id,
                // Знак с точки зрения доступного баланса партнёра.
                'amount' => $this->centsToDecimal(
                    $e->direction === LedgerService::CR ? $e->amount_cents : -$e->amount_cents,
                ),
                'source_type' => $e->source_type, // accrual | withdrawal
                'source_id' => $e->source_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ];
    }

    /**
     * Выписка партнёра за период [from,to] (A2): движения доступного баланса по created_at +
     * сводка (поступления/списания/итог). Источник — тот же ledger (member_available);
     * каждое начисление привязано к событию-«прогону» (source_id). Тип бонуса ledger не
     * хранит — разбивка по типам отдаётся снимком в dashboard(). Деньги — USD-центы/decimal.
     * Примечание: начисления, ушедшие в погашение clawback-долга, в available не попадают —
     * это выписка по доступному балансу (что партнёр реально может тратить).
     */
    public function statement(Member $member, ?string $from, ?string $to): array
    {
        $query = LedgerEntry::query()
            ->where('member_id', $member->id)
            ->where('account_type', LedgerService::ACC_AVAILABLE)
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->get(['id', 'direction', 'amount_cents', 'source_type', 'source_id', 'created_at']);

        $creditedCents = (int) $query->where('direction', LedgerService::CR)->sum('amount_cents');
        $debitedCents = (int) $query->where('direction', LedgerService::DR)->sum('amount_cents');

        return [
            'items' => $query->map(fn (LedgerEntry $e) => [
                'id' => $e->id,
                'amount' => $this->centsToDecimal(
                    $e->direction === LedgerService::CR ? $e->amount_cents : -$e->amount_cents,
                ),
                'source_type' => $e->source_type, // accrual | withdrawal
                'source_id' => $e->source_id,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'summary' => [
                'credited_cents' => $creditedCents,
                'debited_cents' => $debitedCents,
                'net_cents' => $creditedCents - $debitedCents,
            ],
        ];
    }

    /** Центы → строка decimal "D.CC" без float (знак сохраняется). */
    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
