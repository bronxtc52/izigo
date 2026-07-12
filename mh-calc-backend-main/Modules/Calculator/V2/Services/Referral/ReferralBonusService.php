<?php

namespace Modules\Calculator\V2\Services\Referral;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Contracts\StatusReader;
use Modules\Calculator\V2\Models\ReferralReward;
use Modules\Calculator\V2\Services\Volume\ActivationLockGuard;

/**
 * T07: ядро реферальной премии по тирам (CAL-REF-001). Событийно, СРАЗУ после оплаты:
 * получателям на реферальном дереве (sponsor_id) глубиной 1..max_depth начисляется
 * доля BV покупки — L1 10%, L2 0/5/8% по тиру ПОЛУЧАТЕЛЯ (T05 tierAsOf), на ОС.
 *
 * Инварианты (деньги!):
 *  - деньги — integer USD-центы, целочисленная математика: gross = intdiv(base * bps, 10000),
 *    floor (DEC-002: 90 979.2 → 90 979);
 *  - двойная запись — через контракт T02 LedgerV2::credit(ОС), кредит-лот 1 год;
 *  - идемпотентность — UNIQUE(order_id, depth) (skip existing) + ledger idempotency_key
 *    v2:referral:order:{id}:d{depth} (повтор markPaid/webhook = no-op);
 *  - под advisory-lock активаций (взят markPaid): внутренний сервис лишь assertLockHeld();
 *  - stop_at_elite (дефолт FALSE, решение владельца): тир ПОКУПАТЕЛЯ до заказа == ELITE
 *    → explain-строки blocked_elite без денег; crossing-заказ (ELITE достигнут ЭТИМ заказом)
 *    платится всегда (DEC-011);
 *  - тир получателя null (ниже START) или gross 0 → explain-строка zero_rate без денег.
 *
 * Гейт флага mh_v2_referral — снаружи (ReferralBonusStep); сервис фокусируется на расчёте.
 */
class ReferralBonusService
{
    public function __construct(
        private readonly LedgerV2 $ledger,
        private readonly PolicyVersionResolver $policyResolver,
        private readonly StatusReader $status,
        private readonly ReferralRateResolver $rates,
        private readonly ActivationLockGuard $lockGuard,
    ) {
    }

    public function onOrderPaid(int $orderId): void
    {
        $this->lockGuard->assertLockHeld();

        // База BV и покупатель — из immutable-снапшотов заказа T03 (ШОВ базы BV);
        // volume-слой не заснял заказ (флаг mh_v2_volumes OFF) → считать нечего.
        $rows = DB::table('v2_order_volume_snapshots')->where('order_id', $orderId)->get();
        if ($rows->isEmpty()) {
            return;
        }

        $buyerId = (int) $rows->first()->member_id;
        $paidAt = CarbonImmutable::parse($rows->first()->paid_at);
        $baseBvCents = 0;
        foreach ($rows as $r) {
            $baseBvCents += (int) $r->bv_usd_cents;
        }

        $policy = $this->policyResolver->forDate($paidAt);
        $referral = $policy->referral();

        // stop_at_elite — свойство ПОКУПАТЕЛЯ, единое для всех уровней (DEC-011: crossing
        // платится). Дефолт FALSE (решение владельца) → guard дремлет.
        $blocked = $referral->stopAtElite && $this->buyerWasEliteBeforeOrder($buyerId, $orderId, $paidAt);

        $beneficiaryId = $buyerId;
        for ($depth = 1; $depth <= $referral->maxDepth; $depth++) {
            $beneficiaryId = $this->sponsorOf($beneficiaryId);
            if ($beneficiaryId === null) {
                break; // конец реферальной цепочки — выше начислять некому (ноль наград, не ошибка)
            }

            $this->processDepth($orderId, $buyerId, $beneficiaryId, $depth, $baseBvCents, $paidAt, $policy, $blocked);
        }
    }

    /**
     * Начисление/explain для одного уровня. Атомарно и идемпотентно: под локом строки уже
     * взятым оркестратором; проверка UNIQUE(order_id, depth) до записи, ledger idempotent.
     */
    private function processDepth(
        int $orderId,
        int $buyerId,
        int $beneficiaryId,
        int $depth,
        int $baseBvCents,
        CarbonImmutable $paidAt,
        PolicyV2 $policy,
        bool $blocked,
    ): void {
        DB::transaction(function () use ($orderId, $buyerId, $beneficiaryId, $depth, $baseBvCents, $paidAt, $policy, $blocked) {
            if (ReferralReward::query()->where('order_id', $orderId)->where('depth', $depth)->exists()) {
                return; // уровень уже обработан — no-op (повтор webhook/markPaid)
            }

            $tier = $this->status->tierAsOf($beneficiaryId, $paidAt);
            $rateBps = $this->rates->rateBps($policy, $tier, $depth);
            $grossCents = intdiv($baseBvCents * $rateBps, 10000);

            $explain = [
                'depth' => $depth,
                'tier' => $tier,
                'rate_bps' => $rateBps,
                'base_bv_cents' => $baseBvCents,
                'gross_cents' => $grossCents,
                // Округление в пользу компании (DEC-002): дробная часть цента после floor.
                'rounding_remainder_micro' => ($baseBvCents * $rateBps) % 10000,
                'stop_at_elite_blocked' => $blocked,
            ];

            if ($blocked) {
                $this->writeRow($orderId, $buyerId, $beneficiaryId, $depth, $tier, $rateBps, $baseBvCents,
                    $grossCents, ReferralReward::STATUS_BLOCKED_ELITE, $policy, $paidAt, null, $explain);

                return;
            }

            if ($grossCents <= 0) {
                // Ставка 0 (тир null / L2 START) либо база слишком мала для цента — денег нет.
                $this->writeRow($orderId, $buyerId, $beneficiaryId, $depth, $tier, $rateBps, $baseBvCents,
                    $grossCents, ReferralReward::STATUS_ZERO_RATE, $policy, $paidAt, null, $explain);

                return;
            }

            // Кредит ОС через контракт T02: двойная запись + кредит-лот 1 год.
            $idempotencyKey = "v2:referral:order:{$orderId}:d{$depth}";
            $reward = $this->writeRow($orderId, $buyerId, $beneficiaryId, $depth, $tier, $rateBps, $baseBvCents,
                $grossCents, ReferralReward::STATUS_POSTED, $policy, $paidAt, $idempotencyKey, $explain);

            $expiresAt = $paidAt->addDays($policy->accounts()->osLotLifetimeDays);
            $this->ledger->credit(
                $beneficiaryId,
                LedgerV2::SUBACCOUNT_OS,
                $grossCents,
                $idempotencyKey,
                $expiresAt,
                'referral',
                $reward->id,
            );
        });
    }

    private function writeRow(
        int $orderId,
        int $buyerId,
        int $beneficiaryId,
        int $depth,
        ?string $tier,
        int $rateBps,
        int $baseBvCents,
        int $grossCents,
        string $status,
        PolicyV2 $policy,
        CarbonImmutable $paidAt,
        ?string $ledgerKey,
        array $explain,
    ): ReferralReward {
        return ReferralReward::query()->create([
            'order_id' => $orderId,
            'source_member_id' => $buyerId,
            'beneficiary_member_id' => $beneficiaryId,
            'depth' => $depth,
            'tier_snapshot' => $tier,
            'rate_bps' => $rateBps,
            'base_bv_cents' => $baseBvCents,
            'gross_cents' => $grossCents,
            'net_cents' => null, // T11 заполнит после 60%-калибровки
            'status' => $status,
            'policy_version_id' => $policy->versionId(),
            'paid_at' => $paidAt,
            'ledger_idempotency_key' => $ledgerKey,
            'explain' => $explain,
        ]);
    }

    /** Прямой спонсор участника (реферальное дерево sponsor_id), null = корень. */
    private function sponsorOf(int $memberId): ?int
    {
        $sponsorId = DB::table('members')->where('id', $memberId)->value('sponsor_id');

        return $sponsorId === null ? null : (int) $sponsorId;
    }

    /**
     * Был ли покупатель ELITE ДО этого заказа (для guard stop_at_elite). Читает
     * v2_tier_history с ИСКЛЮЧЕНИЕМ строки, порождённой ЭТИМ заказом: crossing-заказ,
     * которым покупатель ТОЛЬКО достиг ELITE, не считается «уже ELITE» (DEC-011 —
     * такой заказ реферальную ещё платит). Прямой as-of-запрос по order-exclusion,
     * которую generic StatusReader::tierAsOf выразить не может.
     */
    private function buyerWasEliteBeforeOrder(int $buyerId, int $orderId, CarbonImmutable $paidAt): bool
    {
        return DB::table('v2_tier_history')
            ->where('member_id', $buyerId)
            ->where('tier', 'ELITE')
            ->where('effective_at', '<=', $paidAt)
            ->where(function ($q) use ($orderId) {
                $q->whereNull('source_order_id')->orWhere('source_order_id', '!=', $orderId);
            })
            ->exists();
    }
}
