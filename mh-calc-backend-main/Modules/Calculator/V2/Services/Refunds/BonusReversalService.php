<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Domain\CalcPeriod;
use Modules\Calculator\V2\Models\LeadershipBonusLine;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\ReferralReward;
use Modules\Calculator\V2\Models\ReversalAction;
use Modules\Calculator\V2\Models\StructureBonus;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;

/**
 * T12: обратные проводки бонусов при возврате.
 *
 * РЕФЕРАЛЬНАЯ — считается точно (провенанс уровня order×depth): начисляется
 * мгновенно на ОС при оплате (CAL-REF-001), поэтому сторнируется НЕМЕДЛЕННО по
 * ORIGINAL rate/tier снапшоту (CAL-REV-001) — суммы строго равны исходной проводке
 * с обратным знаком, даже если тир получателя с тех пор изменился. Частичный
 * возврат — пропорция строго по снапшоту base_bv_cents (rounding-инвариант).
 * Нехватка ОС → clawback-долг (wallet::reverseBonusCredit).
 *
 * СТРУКТУРНАЯ / ЛИДЕРСКАЯ / ГЛОБАЛЬНАЯ — агрегаты периода (не провенанс на заказ):
 * начисляются при ЗАКРЫТИИ окна/месяца. Если PV-лоты заказа уже сматчены и
 * структурная проведена — точный per-order каскад по агрегату неоднозначен и период,
 * как правило, ЗАКРЫТ. По DEC-027 / плану §367 закрытый период НЕ переоткрывается:
 * эффект оформляется ПРЕДЛОЖЕНИЕМ корректирующей проводки (owner-approve → post),
 * а не молчаливым авто-сторно. Если же лоты ещё не сматчены (окно открыто, matching
 * не прогонялся) — структурная не начислена, реверс PV-лотов (T03) уже исключил их
 * из будущего матчинга, отдельная проводка не нужна.
 */
class BonusReversalService
{
    public function __construct(
        private readonly WalletAccountsV2Service $wallet,
        private readonly PeriodCorrectionService $corrections,
    ) {
    }

    /**
     * Сторнировать реферальные премии заказа. Возвращает суммарно сторнированные центы.
     */
    public function reverseReferralForReturn(OrderReturn $return): int
    {
        $rewards = ReferralReward::query()
            ->where('order_id', $return->order_id)
            ->where('status', ReferralReward::STATUS_POSTED)
            ->whereNull('reversed_at')
            ->get();

        $total = 0;
        foreach ($rewards as $reward) {
            // Пропорция строго по ORIGINAL базе BV (снапшот заказа). Полный возврат:
            // returned_bv == base → сторно = gross (без дрейфа центов).
            $base = (int) $reward->base_bv_cents;
            $reverseCents = $base > 0
                ? intdiv($reward->gross_cents * $return->returned_bv_cents, $base)
                : 0;
            $reverseCents = min($reverseCents, (int) $reward->gross_cents);
            if ($reverseCents <= 0) {
                continue;
            }

            $key = "v2:reversal:{$return->id}:referral:{$reward->id}";
            $res = $this->wallet->reverseBonusCredit(
                memberId: $reward->beneficiary_member_id,
                subaccount: LedgerV2::SUBACCOUNT_OS,
                amountCents: $reverseCents,
                idempotencyKey: $key,
                sourceType: 'referral',
                sourceId: $reward->id,
            );

            ReversalAction::query()->firstOrCreate(
                ['idempotency_key' => "{$key}:action"],
                [
                    'return_id' => $return->id,
                    'action_type' => ReversalAction::TYPE_BONUS_REVERSAL,
                    'bonus_type' => ReversalAction::BONUS_REFERRAL,
                    'target_type' => 'referral_reward',
                    'target_id' => $reward->id,
                    'amount_cents' => -$reverseCents, // SIGNED: сторно
                    'snapshot_json' => [
                        'rate_bps' => $reward->rate_bps,
                        'tier_snapshot' => $reward->tier_snapshot,
                        'base_bv_cents' => $base,
                        'gross_cents' => $reward->gross_cents,
                        'debited' => $res['debited'],
                        'clawback' => $res['clawback'],
                    ],
                    'ledger_tx_id' => $res['tx_id'],
                    'status' => ReversalAction::STATUS_POSTED,
                ],
            );

            if ($res['clawback'] > 0) {
                ReversalAction::query()->firstOrCreate(
                    ['idempotency_key' => "{$key}:clawback"],
                    [
                        'return_id' => $return->id,
                        'action_type' => ReversalAction::TYPE_CLAWBACK,
                        'bonus_type' => ReversalAction::BONUS_REFERRAL,
                        'target_type' => 'referral_reward',
                        'target_id' => $reward->id,
                        'amount_cents' => -$res['clawback'],
                        'ledger_tx_id' => $res['tx_id'],
                        'status' => ReversalAction::STATUS_POSTED,
                    ],
                );
            }

            // Полный возврат закрывает награду; частичный — оставляет строку (несколько
            // частичных возвратов суммируются проводками, но не «дважды весь gross»).
            if ($return->kind === OrderReturn::KIND_FULL) {
                $reward->reversed_at = now();
                $reward->reversal_reason = $return->reason;
                $reward->save();
            }

            $total += $reverseCents;
        }

        return $total;
    }

    /**
     * Каскад периодных бонусов (структурная + лидерская) по уже сматченным лотам
     * возврата: предлагает корректирующие проводки для закрытых периодов.
     * Возвращает число предложенных корректировок (>0 → возврат needs_manual).
     *
     * @param int[] $affectedMatchIds
     */
    public function proposePeriodEffectsForReturn(OrderReturn $return, array $affectedMatchIds): int
    {
        if ($affectedMatchIds === []) {
            return 0; // лоты не матчены → структурная не начислена, реверс лотов достаточен
        }

        $structs = StructureBonus::query()
            ->whereIn('match_group_id', $affectedMatchIds)
            ->where('status', StructureBonus::STATUS_POSTED)
            ->get();

        $proposed = 0;
        foreach ($structs as $struct) {
            // Компенсационная оценка возвращённой доли структурной: пропорция
            // возвращённого BV к сматченному BV строки (owner проверяет перед post).
            $matchedBv = max(1, (int) $struct->matched_bv_cents);
            $portion = min((int) $return->returned_bv_cents, $matchedBv);

            // MF-W5-1: структурная ОТКРЫТОГО (незакрытого) периода ещё лежит на НС —
            // не переведена на ОС. Её реверс должен ДЕБЕТОВАТЬ НС и уменьшить месячный
            // бакет ns_month (accrualMonth), иначе перевод НС→ОС перенёс бы сторнированное
            // (контракт-чек W2+ №2). Проводим сразу (авто), owner-approve не нужен —
            // симметрично начислению; лидерский в открытом месяце ещё не начислен
            // (month-only, после калибровки), каскада лидерского нет.
            $period = CalcPeriod::query()->find($struct->period_id);
            if ($period !== null && ! $period->isClosed()) {
                $this->reverseStructuralOnNs($return, $struct, $portion, $matchedBv);

                continue;
            }

            // Закрытый (возможно откалиброванный → на ОС) период: деньги сторнируются
            // корректирующей проводкой на ОС после owner-approve (DEC-027, W2+ №5).
            $estimate = intdiv((int) $struct->net_cents * $portion, $matchedBv);
            if ($estimate <= 0) {
                continue;
            }

            $this->corrections->propose(
                return: $return,
                periodId: $struct->period_id,
                memberId: $struct->member_id,
                bonusType: ReversalAction::BONUS_STRUCTURAL,
                amountCents: -$estimate,
                snapshot: [
                    'structure_bonus_id' => $struct->id,
                    'rank_code' => $struct->rank_code,
                    'rate_bps' => $struct->rate_bps,
                    'net_cents' => $struct->net_cents,
                    'matched_bv_cents' => $struct->matched_bv_cents,
                    'returned_bv_cents' => $return->returned_bv_cents,
                    'basis' => 'compensation_estimate',
                ],
            );
            $proposed++;

            $leads = LeadershipBonusLine::query()
                ->where('source_structure_bonus_id', $struct->id)
                ->where('status', LeadershipBonusLine::STATUS_POSTED)
                ->get();
            foreach ($leads as $lead) {
                $leadEstimate = (int) $struct->net_cents > 0
                    ? intdiv((int) $lead->amount_cents * $estimate, (int) $struct->net_cents)
                    : 0;
                if ($leadEstimate <= 0) {
                    continue;
                }
                $this->corrections->propose(
                    return: $return,
                    periodId: $lead->period_id,
                    memberId: $lead->receiver_member_id,
                    bonusType: ReversalAction::BONUS_LEADERSHIP,
                    amountCents: -$leadEstimate,
                    snapshot: [
                        'leadership_line_id' => $lead->id,
                        'source_structure_bonus_id' => $struct->id,
                        'rate_bp' => $lead->rate_bp,
                        'amount_cents' => $lead->amount_cents,
                        'basis' => 'compensation_estimate',
                    ],
                );
                $proposed++;
            }
        }

        return $proposed;
    }

    /**
     * MF-W5-1: сторно структурной премии, ещё НЕ переведённой на ОС (НС открытого месяца).
     * Дебетует НС на пропорциональную сырую (after_cap) долю с явным accrualMonth —
     * это штампует meta.ns_month на DR-ноге, и executeForCalibratedMonth (нетто CR−DR)
     * корректно уменьшит переносимую на ОС сумму. Нехватка НС → clawback (маловероятно
     * внутри открытого месяца). Провенанс — v2_reversal_actions (posted).
     */
    private function reverseStructuralOnNs(OrderReturn $return, StructureBonus $struct, int $portion, int $matchedBv): void
    {
        // НС хранит СЫРОЙ after_cap (до 60%-калибровки; net_cents ещё == after_cap в
        // открытом месяце). Реверсим сырую долю — перевод сам умножит остаток на factor.
        $rawNs = intdiv((int) $struct->after_cap_cents * $portion, $matchedBv);
        if ($rawNs <= 0) {
            return;
        }

        $key = "v2:reversal:{$return->id}:structural_ns:{$struct->id}";
        $res = $this->wallet->reverseBonusCredit(
            memberId: $struct->member_id,
            subaccount: LedgerV2::SUBACCOUNT_NS,
            amountCents: $rawNs,
            idempotencyKey: $key,
            sourceType: 'structural',
            sourceId: $struct->id,
            accrualMonth: $struct->accrual_month,
        );

        ReversalAction::query()->firstOrCreate(
            ['idempotency_key' => "{$key}:action"],
            [
                'return_id' => $return->id,
                'action_type' => ReversalAction::TYPE_BONUS_REVERSAL,
                'bonus_type' => ReversalAction::BONUS_STRUCTURAL,
                'target_type' => 'structure_bonus',
                'target_id' => $struct->id,
                'amount_cents' => -$rawNs,
                'snapshot_json' => [
                    'accrual_month' => $struct->accrual_month,
                    'after_cap_cents' => (int) $struct->after_cap_cents,
                    'matched_bv_cents' => (int) $struct->matched_bv_cents,
                    'returned_bv_cents' => (int) $return->returned_bv_cents,
                    'debited' => $res['debited'],
                    'clawback' => $res['clawback'],
                    'basis' => 'ns_within_month_reversal',
                ],
                'ledger_tx_id' => $res['tx_id'],
                'status' => ReversalAction::STATUS_POSTED,
            ],
        );
    }
}
