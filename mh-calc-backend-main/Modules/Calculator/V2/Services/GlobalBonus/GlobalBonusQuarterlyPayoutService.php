<?php

namespace Modules\Calculator\V2\Services\GlobalBonus;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Services\FeatureFlag\FeatureFlagService;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Contracts\QuarterGlobalPayoutHandler;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\GlobalBonusAllocation;
use Modules\Calculator\V2\Models\GlobalBonusMonth;
use Modules\Calculator\V2\Models\GlobalBonusPayout;

/**
 * T09 — квартальная выплата глобального пула (handler для оркестратора закрытия
 * квартала T04; перебивает NullQuarterGlobalPayoutHandler в маркер-блоке T09).
 *
 * Вызывается ВНУТРИ транзакции закрытия квартала под ACTIVATION_LOCK (PeriodCloseService).
 * Предикат T04 гарантирует, что все 3 месяца квартала закрыты; здесь дополнительно
 * проверяется, что месяцы глобального бонуса финализированы (draft → отказ, ничего не
 * постится). Σ final_cents трёх месяцев по участнику → ОДНА авто-проводка на ОС
 * (кредит-лот 1 год); idempotency_key v2:glb:q:{quarterId}:m:{memberId} — двойной
 * прогон не задваивает. UNALLOCATED — только снапшот, ledger-проводки нет.
 * Нулевые суммы не постятся. Флаг OFF → no-op (deny-by-default).
 */
class GlobalBonusQuarterlyPayoutService implements QuarterGlobalPayoutHandler
{
    public const FLAG = 'mh_v2_global_bonus';
    public const SOURCE_TYPE = 'global_bonus';

    public function __construct(
        private readonly LedgerV2 $ledger,
        private readonly PolicyVersionResolver $policies,
        private readonly FeatureFlagService $flags,
    ) {
    }

    public function payQuarter(CalcPeriod $quarter, array $monthPeriodIds, string $windowKey): array
    {
        if (! $this->flags->isEnabled(self::FLAG)) {
            return ['handler' => 'global', 'skipped' => 'flag_off', 'members_paid' => 0, 'paid_cents' => 0];
        }

        // Месяцы глобального бонуса обязаны быть финализированы (иначе final_cents не
        // зафиксированы калибровкой) — draft ⇒ отказ закрытия квартала, ничего не постим.
        $months = GlobalBonusMonth::query()
            ->whereIn('month_period_id', $monthPeriodIds)
            ->get()
            ->keyBy('month_period_id');

        foreach ($monthPeriodIds as $periodId) {
            $month = $months->get($periodId);
            if ($month === null) {
                throw new \DomainException("BLOCKED: глобальный бонус месяца period_id={$periodId} не рассчитан — квартал {$windowKey} не выплачивается.");
            }
            if (! $month->isFinal()) {
                throw new \DomainException("BLOCKED: месяц глобального бонуса period_id={$periodId} в статусе draft — квартал {$windowKey} не выплачивается до финализации.");
            }
        }

        $monthIds = $months->pluck('id')->all();

        // Σ final_cents трёх месяцев по участнику (member-строки, ещё не выплаченные).
        $totals = GlobalBonusAllocation::query()
            ->whereIn('global_bonus_month_id', $monthIds)
            ->where('kind', GlobalBonusAllocation::KIND_MEMBER)
            ->where('status', GlobalBonusAllocation::STATUS_ACCRUED)
            ->whereNotNull('member_id')
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(final_cents) AS cents')
            ->orderBy('member_id')
            ->pluck('cents', 'member_id');

        $osLotDays = $this->policies->forDate($quarter->starts_at)->accounts()->osLotLifetimeDays;
        $expiresAt = now()->addDays($osLotDays);

        $membersPaid = 0;
        $paidCents = 0;
        foreach ($totals as $memberId => $cents) {
            $memberId = (int) $memberId;
            $cents = (int) $cents;
            if ($cents <= 0) {
                continue; // нулевые суммы не постятся
            }

            $key = "v2:glb:q:{$quarter->id}:m:{$memberId}";
            $payout = GlobalBonusPayout::query()->firstOrCreate(
                ['quarter_period_id' => $quarter->id, 'member_id' => $memberId],
                [
                    'amount_cents' => $cents,
                    'idempotency_key' => $key,
                    'posted_at' => now(),
                    'status' => GlobalBonusPayout::STATUS_POSTED,
                ],
            );

            // Авто-проводка на ОС (вывод — вручную, как весь контур). Идемпотентно по ключу.
            $this->ledger->credit(
                $memberId,
                LedgerV2::SUBACCOUNT_OS,
                $cents,
                $key,
                $expiresAt,
                self::SOURCE_TYPE,
                $payout->id,
            );

            $membersPaid++;
            $paidCents += $cents;
        }

        // Все member-строки этих месяцев считаются рассчитанными (в т.ч. нулевые) —
        // повторный прогон окна не пересуммирует (accrued → paid идемпотентно).
        GlobalBonusAllocation::query()
            ->whereIn('global_bonus_month_id', $monthIds)
            ->where('kind', GlobalBonusAllocation::KIND_MEMBER)
            ->where('status', GlobalBonusAllocation::STATUS_ACCRUED)
            ->whereNotNull('member_id')
            ->update(['status' => GlobalBonusAllocation::STATUS_PAID]);

        return [
            'handler' => 'global',
            'window' => $windowKey,
            'members_paid' => $membersPaid,
            'paid_cents' => $paidCents,
        ];
    }
}
