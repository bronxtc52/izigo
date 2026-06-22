<?php

namespace Modules\Calculator\Services;

use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Package;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\Rank;
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
     * Отчёт «Балансы»: партнёры с остатками кошелька + сводные итоги по сети.
     * Снимок текущего состояния (без периода). USD. owner/finance.
     */
    public function reportBalances(): array
    {
        $wallets = MemberWallet::query()->get()->keyBy('member_id');

        $rows = Member::query()->orderBy('id')->get(['id', 'name', 'status'])
            ->map(function (Member $m) use ($wallets) {
                $w = $wallets->get($m->id);

                return [
                    'member_id' => $m->id,
                    'name' => $m->name,
                    'status' => $m->status,
                    'available_cents' => (int) ($w->available_cents ?? 0),
                    'held_cents' => (int) ($w->held_cents ?? 0),
                    'clawback_debt_cents' => (int) ($w->clawback_debt_cents ?? 0),
                ];
            })->all();

        return [
            'data' => $rows,
            'totals' => [
                'available_cents' => (int) $wallets->sum('available_cents'),
                'held_cents' => (int) $wallets->sum('held_cents'),
                'clawback_debt_cents' => (int) $wallets->sum('clawback_debt_cents'),
            ],
        ];
    }

    /**
     * Отчёт «Пользователи»: партнёры с рангом/пакетом/статусом + счётчики по статусу.
     * Фильтры: status, период регистрации from/to. owner/finance/support.
     */
    public function reportUsers(array $filters): array
    {
        $rankAlias = Rank::query()->pluck('alias', 'id');
        $packageName = Package::query()->get()->mapWithKeys(fn (Package $p) => [$p->id => $p->name]);

        $query = Member::query()->orderByDesc('id');
        $this->applyDateRange($query, 'created_at', $filters);
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $data = $query->get(['id', 'name', 'status', 'rank_id', 'package_id', 'created_at'])
            ->map(fn (Member $m) => [
                'member_id' => $m->id,
                'name' => $m->name,
                'status' => $m->status,
                'rank' => $m->rank_id ? ($rankAlias[$m->rank_id] ?? "#{$m->rank_id}") : null,
                'package' => $m->package_id ? ($packageName[$m->package_id] ?? "#{$m->package_id}") : null,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->all();

        // Счётчики — по периоду, но без status-фильтра (стабильная сводка по сети).
        $counts = Member::query();
        $this->applyDateRange($counts, 'created_at', $filters);

        return [
            'data' => $data,
            'counts' => [
                'total' => (clone $counts)->count(),
                'active' => (clone $counts)->where('status', 'active')->count(),
                'registered' => (clone $counts)->where('status', 'registered')->count(),
            ],
        ];
    }

    /** Отчёт «Продажи»: оплаченные заказы за период — выручка (центы USD), PV, число. */
    public function reportSales(array $filters): array
    {
        $query = Order::query()->where('status', Order::STATUS_PAID);
        $this->applyDateRange($query, 'created_at', $filters);

        return [
            'orders' => (int) (clone $query)->count(),
            'revenue_cents' => (int) (clone $query)->sum('total_usdt_cents'),
            'pv' => (int) (clone $query)->sum('total_pv'),
        ];
    }

    /**
     * Отчёт «Расход на бонусы». Авторитетный ИТОГ — из ledger (счёт расхода компании,
     * центы, по периоду created_at). Разбивка ПО ТИПАМ — из снимка начислений
     * MemberBonusLine (это текущее состояние сети, НЕ исторический период): даёт структуру
     * расхода, поэтому помечена *_snapshot и периодом не фильтруется. Движок не трогаем — читаем.
     */
    public function reportBonusExpense(array $filters): array
    {
        $expense = LedgerEntry::query()
            ->where('account_type', LedgerService::ACC_COMMISSION_EXPENSE)
            ->whereNull('member_id');
        $this->applyDateRange($expense, 'created_at', $filters);

        $totalCents = (int) $expense
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount_cents ELSE -amount_cents END), 0) AS net")
            ->value('net');

        $byType = MemberBonusLine::query()
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('type')
            ->pluck('amount', 'type');

        $byTypeSnapshot = collect(['binary', 'referral', 'leader', 'rank'])->map(fn (string $t) => [
            'type' => $t,
            'amount_cents' => (int) round(((float) ($byType[$t] ?? 0)) * 100),
        ])->all();

        return [
            'total_expense_cents' => $totalCents,
            'by_type_snapshot' => $byTypeSnapshot,
        ];
    }

    /** Фильтр по периоду [from,to] (включительно по дню) на указанной колонке. */
    private function applyDateRange($query, string $column, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->whereDate($column, '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate($column, '<=', $filters['to']);
        }
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
