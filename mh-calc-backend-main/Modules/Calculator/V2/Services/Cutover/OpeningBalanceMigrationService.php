<?php

namespace Modules\Calculator\V2\Services\Cutover;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;

/**
 * mh-full-plan T15 (W6): перенос денежного main-баланса V1 → ОС V2 (opening).
 *
 * Каждый партнёр с available_cents>0 получает reclass-группу проводок
 *   Dr member_available (V1) / Cr member_os_available (V2)  (один tx_id)
 * и БЕССРОЧНЫЙ opening-лот ОС (expires_at=null) — решение владельца
 * (dec-triage 2026-07-12: денежные балансы переносятся, срок жизни НЕ навешивается;
 * PV/тиры/статусы стартуют с нуля — их этот сервис НЕ трогает).
 *
 * Инварианты:
 *  - Σ available (до) == Σ ОС opening (после); trial balance == 0 (двойная запись);
 *  - held_cents / clawback_debt_cents НЕ трогаются — открытые заявки на вывод
 *    доживают по V1-пути (LedgerService::hold/release/markPaid);
 *  - идемпотентно по ledger idempotency_key 'v2migrate:opening:m{id}' и по unique
 *    idempotency_key лота: повтор = no-op, opening-лоты не задваиваются.
 *
 * Пишет ТОЛЬКО через примитивы T02 (LedgerPostingV2Service / WalletLotV2 / кэш
 * MemberAccountV2 через WalletAccountsV2Service); своих счетов не заводит.
 * commitAll/migrateMember вызывать ТОЛЬКО внутри внешней транзакции, держащей
 * ACTIVATION_LOCK (команда calc-v2:cutover-migrate) — сериализация с пересчётами V1.
 */
class OpeningBalanceMigrationService
{
    /**
     * Единый провенанс opening-переноса и для ledger-проводки, и для лота ОС
     * (should-fix #5). Значение ≤16 символов — влезает в ledger_entries.source_type
     * varchar(16) (лот допускает до 32).
     */
    public const SOURCE_TYPE = 'v2_opening';

    public function __construct(
        private readonly LedgerPostingV2Service $poster,
        private readonly WalletAccountsV2Service $accounts,
    ) {
    }

    /** Ключ идемпотентности reclass-проводки участника (префикс зарезервирован T15). */
    public function keyFor(int $memberId): string
    {
        return "v2migrate:opening:m{$memberId}";
    }

    /**
     * Read-only план переноса (dry-run): по каждому участнику с available_cents>0 —
     * сколько уйдёт на ОС и был ли он уже перенесён. Без единой записи.
     *
     * @return array<int,array{member_id:int,available_cents:int,already_migrated:bool}>
     */
    public function plan(): array
    {
        $rows = [];
        $wallets = MemberWallet::query()
            ->where('available_cents', '>', 0)
            ->orderBy('member_id')
            ->get(['member_id', 'available_cents']);

        foreach ($wallets as $w) {
            $rows[] = [
                'member_id' => (int) $w->member_id,
                'available_cents' => (int) $w->available_cents,
                'already_migrated' => $this->poster->alreadyPosted($this->keyFor((int) $w->member_id)),
            ];
        }

        return $rows;
    }

    /** Σ денег к переносу (dry-run). */
    public function projectedTotalCents(): int
    {
        return (int) MemberWallet::query()->where('available_cents', '>', 0)->sum('available_cents');
    }

    /**
     * Выполнить перенос ВСЕХ участников. Вызывать ТОЛЬКО внутри внешней транзакции,
     * держащей ACTIVATION_LOCK.
     *
     * @return array{migrated:int,skipped:int,total_cents:int,entries:array<int,array{member_id:int,amount_cents:int,tx_id:string}>}
     */
    public function commitAll(): array
    {
        $this->assertInTransaction();

        $migrated = 0;
        $skipped = 0;
        $total = 0;
        $entries = [];

        $memberIds = MemberWallet::query()
            ->where('available_cents', '>', 0)
            ->orderBy('member_id')
            ->pluck('member_id');

        foreach ($memberIds as $mid) {
            $entry = $this->migrateMember((int) $mid);
            if ($entry === null) {
                $skipped++;

                continue;
            }
            $migrated++;
            $total += $entry['amount_cents'];
            $entries[] = $entry;
        }

        return ['migrated' => $migrated, 'skipped' => $skipped, 'total_cents' => $total, 'entries' => $entries];
    }

    /**
     * Перенести одного участника (reclass + opening-лот + оба кэша). Идемпотентно.
     * Возвращает null, если переносить нечего или уже перенесён.
     *
     * @return array{member_id:int,amount_cents:int,tx_id:string}|null
     */
    public function migrateMember(int $memberId): ?array
    {
        $this->assertInTransaction();

        $key = $this->keyFor($memberId);
        if ($this->poster->alreadyPosted($key)) {
            return null; // идемпотентно
        }

        // Единый порядок локов: V1 wallet → V2 account.
        $wallet = MemberWallet::query()->where('member_id', $memberId)->lockForUpdate()->first();
        $available = $wallet !== null ? (int) $wallet->available_cents : 0;
        if ($available <= 0) {
            return null;
        }
        $account = $this->accounts->lockAccount($memberId);

        // Reclass: Dr member_available / Cr member_os_available (Σ сохраняется, trial balance == 0).
        // Единый source_type для проводки и лота (self::SOURCE_TYPE ≤16 символов).
        $txId = $this->poster->post([
            $this->poster->leg($memberId, LedgerService::ACC_AVAILABLE, LedgerPostingV2Service::DR, $available),
            $this->poster->leg($memberId, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::CR, $available),
        ], self::SOURCE_TYPE, null, $key, ['from' => 'member_available', 'to' => 'os_available']);

        // Бессрочный opening-лот ОС (MF-9 допускает expires_at=null; owner-решение по деньгам).
        WalletLotV2::query()->create([
            'member_id' => $memberId,
            'account' => WalletLotV2::ACCOUNT_OS,
            'amount_cents' => $available,
            'available_cents' => $available,
            'earned_at' => now(),
            'expires_at' => null, // бессрочный — деньги партнёра не сгорают на cutover
            'source_type' => self::SOURCE_TYPE,
            'status' => WalletLotV2::STATUS_ACTIVE,
            'idempotency_key' => $key,
        ]);

        // Кэши: V1 available уходит в ноль на сумму переноса; V2 ОС растёт.
        $wallet->available_cents -= $available;
        $wallet->updated_at = now();
        $wallet->save();

        $account->os_available_cents += $available;
        $this->accounts->saveAccount($account);

        return ['member_id' => $memberId, 'amount_cents' => $available, 'tx_id' => $txId];
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \RuntimeException(
                'OpeningBalanceMigrationService: перенос должен идти внутри транзакции, держащей ACTIVATION_LOCK',
            );
        }
    }
}
