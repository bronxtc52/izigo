<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\WithdrawalRequest;

/**
 * Read-модели для разделов админки: Дашборд (KPI), Финансы (ledger + кошелёк партнёра),
 * Операции (платежи, autoship). Только чтение; RBAC-гейты — на маршрутах.
 */
class AdminReportService
{
    /** KPI на главном экране админки. */
    public function dashboard(): array
    {
        $pending = WithdrawalRequest::query()->where('status', WithdrawalRequest::STATUS_REQUESTED);

        return [
            'members_total' => Member::query()->count(),
            'members_active' => Member::query()->where('status', 'active')->count(),
            'withdrawals_pending' => (clone $pending)->count(),
            'withdrawals_pending_amount_cents' => (int) (clone $pending)->sum('amount_cents'),
            // Остатки счетов компании как положительные величины по нормальной стороне:
            // выручка — credit-нормальна; расход комиссий и выплаты — debit-нормальны.
            'company_sales_revenue_cents' => $this->companyAccountNet(LedgerService::ACC_SALES_REVENUE),
            'company_commission_expense_cents' => $this->companyAccountNet(LedgerService::ACC_COMMISSION_EXPENSE, debitNormal: true),
            'company_payouts_paid_cents' => $this->companyAccountNet(LedgerService::ACC_PAYOUTS_PAID, debitNormal: true),
        ];
    }

    /** Журнал проводок с фильтрами (member_id / account_type / source_type), новые сверху. */
    public function ledger(array $filters): array
    {
        $query = LedgerEntry::query()->orderByDesc('id');

        if (!empty($filters['member_id'])) {
            $query->where('member_id', (int) $filters['member_id']);
        }
        if (!empty($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }
        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($page, fn (LedgerEntry $e) => [
            'id' => $e->id,
            'tx_id' => $e->tx_id,
            'member_id' => $e->member_id,
            'account_type' => $e->account_type,
            'direction' => $e->direction,
            'amount_cents' => $e->amount_cents,
            'source_type' => $e->source_type,
            'source_id' => $e->source_id,
            'created_at' => $e->created_at?->toIso8601String(),
        ]);
    }

    /** Кошелёк партнёра (кэш баланса). Нет записи — нули. */
    public function memberWallet(int $memberId): array
    {
        Member::query()->findOrFail($memberId);
        $w = MemberWallet::query()->where('member_id', $memberId)->first();

        return [
            'member_id' => $memberId,
            'available_cents' => (int) ($w->available_cents ?? 0),
            'held_cents' => (int) ($w->held_cents ?? 0),
            'clawback_debt_cents' => (int) ($w->clawback_debt_cents ?? 0),
            'currency' => $w->currency ?? 'USD',
        ];
    }

    /** Платежи (приём): фильтр по статусу/назначению. */
    public function payments(array $filters): array
    {
        $query = Payment::query()->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['purpose'])) {
            $query->where('purpose', $filters['purpose']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($page, fn (Payment $p) => [
            'id' => $p->id,
            'order_id' => $p->order_id,
            'member_id' => $p->member_id,
            'purpose' => $p->purpose,
            'amount_cents' => $p->amount_cents,
            'status' => $p->status,
            'external_ref' => $p->external_ref,
            'created_at' => $p->created_at?->toIso8601String(),
        ]);
    }

    /** Autoship-подписки: фильтр по статусу. */
    public function autoship(array $filters): array
    {
        $query = AutoshipSubscription::query()->orderByDesc('id');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($page, fn (AutoshipSubscription $a) => [
            'id' => $a->id,
            'member_id' => $a->member_id,
            'product_id' => $a->product_id,
            'package_id' => $a->package_id,
            'interval_days' => $a->interval_days,
            'next_charge_at' => $a->next_charge_at?->toIso8601String(),
            'status' => $a->status,
            'retry_stage' => $a->retry_stage,
        ]);
    }

    /**
     * Остаток счёта компании (member_id IS NULL) как положительная величина по нормальной
     * стороне: credit-нормальный (выручка) — Σcredit−Σdebit; debit-нормальный (расход/выплаты)
     * — Σdebit−Σcredit.
     */
    private function companyAccountNet(string $accountType, bool $debitNormal = false): int
    {
        $expr = $debitNormal
            ? "SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END)"
            : "SUM(CASE WHEN direction = 'credit' THEN amount_cents ELSE -amount_cents END)";

        return (int) LedgerEntry::query()
            ->where('account_type', $accountType)
            ->whereNull('member_id')
            ->selectRaw("COALESCE({$expr}, 0) AS net")
            ->value('net');
    }

    /** @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $page */
    private function paginated($page, callable $row): array
    {
        return [
            'data' => collect($page->items())->map($row)->all(),
            'total' => $page->total(),
            'per_page' => $page->perPage(),
            'current_page' => $page->currentPage(),
        ];
    }
}
