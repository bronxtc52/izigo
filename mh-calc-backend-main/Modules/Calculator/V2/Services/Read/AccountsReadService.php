<?php

namespace Modules\Calculator\V2\Services\Read;

use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;

/**
 * mh-full-plan T14: read-проекция счетов ОС/НС/БС для Mini App поверх таблиц T02
 * (v2_member_accounts / v2_wallet_lots / ledger_entries). НИЧЕГО не пишет в ledger.
 * Деньги — integer USD-центы + строковое decimal (без float); лоты — earliest-
 * expiry-first (истёкшие/потреблённые исключены); история — cursor-пагинация по id
 * проводки, скоуп по member (IDOR закрыт вызывающим контроллером через auth-члена).
 *
 * Единственная истина по балансам — кэш v2_member_accounts (обновляется T02 в той же
 * транзакции, что и ledger); тест плана сверяет его с суммой ledger по субсчёту.
 */
class AccountsReadService
{
    use CentsFormat;

    /** Коды субсчетов, доступных партнёру (валидация {account} в роуте). */
    public const ACCOUNTS = ['os', 'ns', 'bs'];

    /** Субсчёт → ledger account_type'ы (для истории движений). */
    private const LEDGER_ACCOUNTS = [
        'os' => [LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::ACC_OS_HELD],
        'ns' => [LedgerPostingV2Service::ACC_NS],
        'bs' => [LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::ACC_BS_HELD],
    ];

    private const ALL_LEDGER_ACCOUNTS = [
        LedgerPostingV2Service::ACC_OS_AVAILABLE,
        LedgerPostingV2Service::ACC_OS_HELD,
        LedgerPostingV2Service::ACC_NS,
        LedgerPostingV2Service::ACC_BS_AVAILABLE,
        LedgerPostingV2Service::ACC_BS_HELD,
    ];

    /** Порог «скоро сгорит» для подсветки на фронте (30 дней). */
    private const EXPIRY_SOON_DAYS = 30;

    public function __construct(private readonly PolicyVersionResolver $policyResolver)
    {
    }

    /**
     * Балансы трёх счетов + ближайшие сгорания лотов + параметры (лимит оплаты с ОС,
     * дата ближайшего перевода НС→ОС). Для шапки таба «Счета».
     */
    public function accounts(int $memberId): array
    {
        $a = MemberAccountV2::query()->where('member_id', $memberId)->first();

        $cents = [
            'os_available_cents' => (int) ($a->os_available_cents ?? 0),
            'os_held_cents' => (int) ($a->os_held_cents ?? 0),
            'ns_cents' => (int) ($a->ns_cents ?? 0),
            'bs_available_cents' => (int) ($a->bs_available_cents ?? 0),
            'bs_held_cents' => (int) ($a->bs_held_cents ?? 0),
        ];

        return $cents + [
            'os_available' => $this->centsToDecimal($cents['os_available_cents']),
            'os_held' => $this->centsToDecimal($cents['os_held_cents']),
            'ns' => $this->centsToDecimal($cents['ns_cents']),
            'bs_available' => $this->centsToDecimal($cents['bs_available_cents']),
            'bs_held' => $this->centsToDecimal($cents['bs_held_cents']),
            'currency' => 'USD',
            // ОС: вывод + оплата заказа ≤ N% (bp → pct).
            'os_withdrawable' => true,
            'order_pay_limit_pct' => intdiv($this->osOrderShareBp(), 100),
            // НС: транзитный, перевод в ОС после закрытия/калибровки месяца (MF-4).
            'ns_next_transfer_at' => $this->nextNsTransferAt(),
            // БС: только покупки, не выводится.
            'bs_withdrawable' => false,
            'upcoming_expirations' => $this->upcomingExpirations($memberId),
        ];
    }

    /**
     * Активные лоты субсчёта, earliest-expiry-first. НС лотов не имеет (плоский) —
     * пустой список. Каждый лот помечается expiring_soon (<30 дней до сгорания).
     */
    public function lots(int $memberId, string $account): array
    {
        if ($account === 'ns') {
            return ['account' => 'ns', 'items' => []];
        }

        $now = now();
        $lots = WalletLotV2::query()
            ->where('member_id', $memberId)
            ->where('account', $account)
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->where('available_cents', '>', 0)
            ->orderByRaw('expires_at ASC NULLS LAST, id ASC')
            ->get()
            ->map(fn (WalletLotV2 $lot) => [
                'id' => $lot->id,
                'account' => $lot->account,
                'amount_cents' => (int) $lot->amount_cents,
                'available_cents' => (int) $lot->available_cents,
                'available' => $this->centsToDecimal((int) $lot->available_cents),
                'earned_at' => $lot->earned_at?->toIso8601String(),
                'expires_at' => $lot->expires_at?->toIso8601String(), // null = не сгорает (награды, MF-9)
                'expiring_soon' => $lot->expires_at !== null
                    && $lot->expires_at->lessThanOrEqualTo($now->copy()->addDays(self::EXPIRY_SOON_DAYS)),
                'source_type' => $lot->source_type,
            ])->all();

        return ['account' => $account, 'items' => $lots];
    }

    /**
     * Лента движений субсчёта (или всех V2-субсчетов при $account=null), новые сверху,
     * cursor-пагинация по id проводки (стабильна, не течёт между участниками — фильтр
     * по member_id обязателен). amount_cents со знаком партнёра: credit = приход.
     */
    public function history(int $memberId, ?string $account, ?int $cursor, int $limit): array
    {
        $accounts = $account === null ? self::ALL_LEDGER_ACCOUNTS : (self::LEDGER_ACCOUNTS[$account] ?? []);
        $limit = max(1, min($limit, 100));

        $entries = LedgerEntry::query()
            ->where('member_id', $memberId)
            ->whereIn('account_type', $accounts)
            ->when($cursor !== null, fn ($q) => $q->where('id', '<', $cursor))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['id', 'account_type', 'direction', 'amount_cents', 'source_type', 'source_id', 'created_at']);

        $hasMore = $entries->count() > $limit;
        $page = $entries->take($limit);

        return [
            'items' => $page->map(function (LedgerEntry $e) {
                $signed = $e->direction === LedgerPostingV2Service::CR ? (int) $e->amount_cents : -(int) $e->amount_cents;

                return [
                    'id' => $e->id,
                    'account_type' => $e->account_type,
                    'subaccount' => $this->subaccountOf($e->account_type),
                    'amount_cents' => $signed,
                    'amount' => $this->centsToDecimal($signed),
                    'source_type' => $e->source_type,
                    'source_id' => $e->source_id,
                    'created_at' => $e->created_at?->toIso8601String(),
                ];
            })->values()->all(),
            'next_cursor' => $hasMore ? $page->last()->id : null,
        ];
    }

    // ------------------------------------------------------------------

    /** До трёх ближайших сгораний активных лотов (для шапки счетов). */
    private function upcomingExpirations(int $memberId): array
    {
        return WalletLotV2::query()
            ->where('member_id', $memberId)
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->where('available_cents', '>', 0)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')->orderBy('id')
            ->limit(3)
            ->get(['account', 'available_cents', 'expires_at'])
            ->map(fn (WalletLotV2 $lot) => [
                'account' => $lot->account,
                'amount_cents' => (int) $lot->available_cents,
                'amount' => $this->centsToDecimal((int) $lot->available_cents),
                'expires_at' => $lot->expires_at?->toIso8601String(),
            ])->all();
    }

    private function subaccountOf(string $accountType): string
    {
        return match ($accountType) {
            LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::ACC_OS_HELD => 'os',
            LedgerPostingV2Service::ACC_NS => 'ns',
            default => 'bs',
        };
    }

    private function osOrderShareBp(): int
    {
        try {
            return $this->policyResolver->current()->accounts()->osMaxOrderPaymentShareBp;
        } catch (\Throwable) {
            return 7000; // fail-safe дефолт Гейта A (70%)
        }
    }

    /**
     * Ближайшая дата перевода НС→ОС. Канон MF-4: перевод job'ом 1-го числа за прошедший
     * месяц (после закрытия/калибровки) — показываем партнёру 1-е число СЛЕДУЮЩЕГО месяца
     * (ISO-8601 UTC). Чистая проекция расписания, без обращения к ledger.
     */
    private function nextNsTransferAt(): string
    {
        return now('UTC')->startOfMonth()->addMonth()->toIso8601String();
    }
}
