<?php

namespace Modules\Calculator\V2\Services\Cutover;

use Modules\Calculator\Models\LedgerEntry;
use Modules\Calculator\Models\MemberWallet;
use Modules\Calculator\Models\V2\MemberAccountV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;

/**
 * mh-full-plan T15 (W6): read-only сверка ledger'а — прекондишен cutover и проверка
 * после rollback. Три инварианта:
 *   1) trial balance: Σdebit == Σcredit глобально и в каждой tx_id-группе;
 *   2) кэши == свёртка ledger по member+account (V1 member_wallets и V2 v2_member_accounts);
 *   3) Σ активных лотов ОС/БС == соответствующий доступный кэш (v2_wallet_lots).
 * ok=true только если все дельты нулевые. Ничего не пишет.
 */
class LedgerReconciliationService
{
    /** Счета «credit-normal» (кредит увеличивает баланс). */
    private const CREDIT_NORMAL = [
        LedgerService::ACC_AVAILABLE,
        LedgerService::ACC_HELD,
        LedgerPostingV2Service::ACC_OS_AVAILABLE,
        LedgerPostingV2Service::ACC_OS_HELD,
        LedgerPostingV2Service::ACC_NS,
        LedgerPostingV2Service::ACC_BS_AVAILABLE,
        LedgerPostingV2Service::ACC_BS_HELD,
    ];

    /**
     * @return array{
     *   ok:bool,
     *   trial_balance:array{debit:int,credit:int,delta:int},
     *   unbalanced_tx:array<int,string>,
     *   cache_drift:array<int,array{member_id:int,account:string,cache:int,ledger:int,delta:int}>,
     *   lot_drift:array<int,array{member_id:int,account:string,cache:int,lots:int,delta:int}>
     * }
     */
    public function check(): array
    {
        $trial = $this->trialBalance();
        $unbalanced = $this->unbalancedTransactions();
        $ledgerFold = $this->ledgerFoldByMemberAccount();

        $cacheDrift = [
            ...$this->walletDrift($ledgerFold),
            ...$this->accountDrift($ledgerFold),
        ];
        $lotDrift = $this->lotDrift();

        $ok = $trial['delta'] === 0
            && $unbalanced === []
            && $cacheDrift === []
            && $lotDrift === [];

        return [
            'ok' => $ok,
            'trial_balance' => $trial,
            'unbalanced_tx' => $unbalanced,
            'cache_drift' => $cacheDrift,
            'lot_drift' => $lotDrift,
        ];
    }

    /** @return array{debit:int,credit:int,delta:int} */
    private function trialBalance(): array
    {
        $debit = (int) LedgerEntry::query()->where('direction', LedgerPostingV2Service::DR)->sum('amount_cents');
        $credit = (int) LedgerEntry::query()->where('direction', LedgerPostingV2Service::CR)->sum('amount_cents');

        return ['debit' => $debit, 'credit' => $credit, 'delta' => $debit - $credit];
    }

    /** @return array<int,string> tx_id-группы с Σdebit != Σcredit */
    private function unbalancedTransactions(): array
    {
        return LedgerEntry::query()
            ->selectRaw("tx_id, SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END) AS bal", [LedgerPostingV2Service::DR])
            ->groupBy('tx_id')
            ->havingRaw("SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END) <> 0", [LedgerPostingV2Service::DR])
            ->pluck('tx_id')
            ->all();
    }

    /** @return array<int,array<string,int>> [memberId][account_type] = Σcredit − Σdebit */
    private function ledgerFoldByMemberAccount(): array
    {
        $rows = LedgerEntry::query()
            ->whereNotNull('member_id')
            ->selectRaw("member_id, account_type, SUM(CASE WHEN direction = ? THEN amount_cents ELSE -amount_cents END) AS bal", [LedgerPostingV2Service::CR])
            ->groupBy('member_id', 'account_type')
            ->get();

        $fold = [];
        foreach ($rows as $r) {
            $fold[(int) $r->member_id][$r->account_type] = (int) $r->bal;
        }

        return $fold;
    }

    /** V1 member_wallets vs ledger fold. */
    private function walletDrift(array $fold): array
    {
        $drift = [];
        foreach (MemberWallet::query()->get(['member_id', 'available_cents', 'held_cents', 'clawback_debt_cents']) as $w) {
            $mid = (int) $w->member_id;
            // credit-normal: available/held.
            $drift = [
                ...$drift,
                ...$this->cmp($mid, LedgerService::ACC_AVAILABLE, (int) $w->available_cents, $fold[$mid][LedgerService::ACC_AVAILABLE] ?? 0),
                ...$this->cmp($mid, LedgerService::ACC_HELD, (int) $w->held_cents, $fold[$mid][LedgerService::ACC_HELD] ?? 0),
            ];
            // clawback debt — debit-normal: положительный долг = Σdebit − Σcredit = −fold.
            $ledgerDebt = -($fold[$mid][LedgerService::ACC_CLAWBACK_DEBT] ?? 0);
            $drift = [...$drift, ...$this->cmp($mid, LedgerService::ACC_CLAWBACK_DEBT, (int) $w->clawback_debt_cents, $ledgerDebt)];
        }

        return $drift;
    }

    /** V2 v2_member_accounts vs ledger fold. */
    private function accountDrift(array $fold): array
    {
        $drift = [];
        $map = [
            'os_available_cents' => LedgerPostingV2Service::ACC_OS_AVAILABLE,
            'os_held_cents' => LedgerPostingV2Service::ACC_OS_HELD,
            'ns_cents' => LedgerPostingV2Service::ACC_NS,
            'bs_available_cents' => LedgerPostingV2Service::ACC_BS_AVAILABLE,
            'bs_held_cents' => LedgerPostingV2Service::ACC_BS_HELD,
        ];
        foreach (MemberAccountV2::query()->get() as $a) {
            $mid = (int) $a->member_id;
            foreach ($map as $column => $account) {
                $drift = [...$drift, ...$this->cmp($mid, $account, (int) $a->{$column}, $fold[$mid][$account] ?? 0)];
            }
        }

        return $drift;
    }

    /** Σ активных лотов ОС/БС vs доступный кэш v2_member_accounts. */
    private function lotDrift(): array
    {
        $lotSums = WalletLotV2::query()
            ->where('status', WalletLotV2::STATUS_ACTIVE)
            ->selectRaw('member_id, account, SUM(available_cents) AS s')
            ->groupBy('member_id', 'account')
            ->get();

        $byMember = []; // [memberId][account] = Σ lots
        foreach ($lotSums as $row) {
            $byMember[(int) $row->member_id][$row->account] = (int) $row->s;
        }

        $accounts = MemberAccountV2::query()->get()->keyBy('member_id');
        $memberIds = array_unique([...array_keys($byMember), ...$accounts->keys()->map(fn ($k) => (int) $k)->all()]);

        $drift = [];
        foreach ($memberIds as $mid) {
            $account = $accounts->get($mid);
            $osCache = $account ? (int) $account->os_available_cents : 0;
            $bsCache = $account ? (int) $account->bs_available_cents : 0;
            $osLots = $byMember[$mid][WalletLotV2::ACCOUNT_OS] ?? 0;
            $bsLots = $byMember[$mid][WalletLotV2::ACCOUNT_BS] ?? 0;

            if ($osCache !== $osLots) {
                $drift[] = ['member_id' => $mid, 'account' => 'os', 'cache' => $osCache, 'lots' => $osLots, 'delta' => $osCache - $osLots];
            }
            if ($bsCache !== $bsLots) {
                $drift[] = ['member_id' => $mid, 'account' => 'bs', 'cache' => $bsCache, 'lots' => $bsLots, 'delta' => $bsCache - $bsLots];
            }
        }

        return $drift;
    }

    /** @return array<int,array{member_id:int,account:string,cache:int,ledger:int,delta:int}> */
    private function cmp(int $memberId, string $account, int $cache, int $ledger): array
    {
        if ($cache === $ledger) {
            return [];
        }

        return [['member_id' => $memberId, 'account' => $account, 'cache' => $cache, 'ledger' => $ledger, 'delta' => $cache - $ledger]];
    }
}
