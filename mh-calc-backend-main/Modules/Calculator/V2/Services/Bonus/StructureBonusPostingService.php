<?php

namespace Modules\Calculator\V2\Services\Bonus;

use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Periods\PeriodService;

/**
 * T06: отдельный шаг posting структурной премии — calculated-строки окна проводятся
 * на субсчёт НС (LedgerV2::credit, двойная запись). Начисление на НС с явным
 * accrual_month (месяц окна) — перевод НС→ОС переводит строго начисления
 * откалиброванного месяца (amendments MF-3/MF-4); сам перевод — зона T04, не T06.
 *
 * Идемпотентность двойная: (1) posting_idempotency_key уникален, credit() —
 * no-op на повтор (alreadyPosted); (2) status calculated→posted, повторный прогон
 * пропускает уже проведённые. НС-лоты не заводятся (НС — плоский транзитный
 * субсчёт); expiresAt не передаётся. net_cents = after_cap_cents до появления
 * 60%-пула T11 (тогда net пересчитается ПЕРЕД этим шагом).
 */
class StructureBonusPostingService
{
    public function __construct(
        private readonly LedgerV2 $ledger,
        private readonly PeriodService $periods,
    ) {
    }

    /**
     * Провести все calculated-строки окна на НС.
     *
     * @return array{posted:int,ns_credited_cents:int,zero_rows:int}
     */
    public function postForPeriod(CalcPeriod $period): array
    {
        // Контракт T04: закрытый период неизменяем (allowClosing=true — внутри пайплайна).
        $this->periods->assertOpen($period, allowClosing: true);

        $rows = StructureBonus::query()
            ->where('period_id', $period->id)
            ->where('status', StructureBonus::STATUS_CALCULATED)
            ->orderBy('member_id')
            ->get();

        $posted = 0;
        $credited = 0;
        $zero = 0;

        foreach ($rows as $row) {
            $net = $row->net_cents;
            if ($net > 0) {
                $this->ledger->credit(
                    memberId: $row->member_id,
                    subaccount: LedgerV2::SUBACCOUNT_NS,
                    amountCents: $net,
                    idempotencyKey: $row->posting_idempotency_key,
                    expiresAt: null,
                    sourceType: StructureBonus::SOURCE_TYPE,
                    sourceId: $row->id,
                    accrualMonth: $row->accrual_month,
                );
                $credited += $net;
            } else {
                $zero++; // нулевая строка (matched=0 или cap=0) — сохранена для explainability, денег нет
            }

            $row->status = StructureBonus::STATUS_POSTED;
            $row->save();
            $posted++;
        }

        return ['posted' => $posted, 'ns_credited_cents' => $credited, 'zero_rows' => $zero];
    }
}
