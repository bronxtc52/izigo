<?php

namespace Modules\Calculator\V2\Services\Awards;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\V2\WalletLotConsumptionV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Services\AuditLogService;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Contracts\GlobalQualificationAwardHook;
use Modules\Calculator\V2\Contracts\LedgerV2;
use Modules\Calculator\V2\Contracts\PolicyV2;
use Modules\Calculator\V2\Contracts\PolicyVersionResolver;
use Modules\Calculator\V2\Domain\Policy\StatusCode;
use Modules\Calculator\V2\Models\AwardEntitlement;
use Modules\Calculator\V2\Models\RankHistory;
use Modules\Calculator\V2\Services\Awards\Exceptions\AwardConflictException;
use Modules\Calculator\V2\Services\Awards\Exceptions\AwardNotFoundException;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\WalletAccountsV2Service;

/**
 * mh-full-plan T10: ядро квалификационных наград USD (единоразовые суммы за статусы
 * Manager..VP на Бонусный счёт, ручная выплата).
 *
 * Модель:
 *  - грант происходит АВТОМАТИЧЕСКИ при достижении ранга (триггер — строки
 *    v2_rank_history T05, «все пройденные при скачке» DEC-040) и при квалификации
 *    глобального бонуса в ранге VP (этапы 2-3, DEC-042 спека A). На каждый —
 *    entitlement (unique(member, code, stage) — идемпотентность) + ОТДЕЛЬНАЯ
 *    сбалансированная проводка кредита на БС через контракт T02 LedgerV2::credit
 *    (source_type='award', expires_at NULL — награды НЕ сгорают, MF-9);
 *  - amount_cents снапшотится из PolicyVersion на дату события (провенанс);
 *  - выплата ВРУЧНУЮ (markPaid, owner-only): адресная проводка award-лота
 *    БС → company_payouts_paid; hold/release/forfeit — ручные решения админа
 *    (DEC-041/043), forfeit НЕ создаёт reversal-проводок и НЕ удаляет начисление
 *    (DEC-027/DEC-020 — ранг навсегда, награды при возвратах не отзываются).
 *
 * Идемпотентность денег: insertOrIgnore(entitlement) + ledger idempotency_key
 * (v2award:{member}:{code}:{stage}) — конкурентный повтор даёт ровно одну проводку
 * (ON CONFLICT DO NOTHING, без Postgres-aborted-tx). Работает под advisory-lock
 * оркестратора (взят пайплайном пост-оплаты / T09-оркестратором), собственного
 * ACTIVATION_LOCK не берёт (nice-to-have #5 amendments: внутренний сервис).
 *
 * T02 не предоставил метод «выплата с БС» (риск-карта плана T10) — payout-проводка
 * собирается здесь через публичный LedgerPostingV2Service + lockAccount/saveAccount
 * WalletAccountsV2Service, с АДРЕСНЫМ списанием именно award-лота (не FIFO, иначе
 * съело бы чужие покупочные БС-лоты). См. deviation-лог в отчёте задачи.
 */
class QualificationAwardService implements GlobalQualificationAwardHook
{
    public function __construct(
        private readonly WalletAccountsV2Service $wallet,
        private readonly LedgerPostingV2Service $poster,
        private readonly PolicyVersionResolver $policyResolver,
        private readonly AuditLogService $audit,
    ) {
    }

    // ------------------------------------------------------------------
    // Грант наград по достижению ранга (DEC-040)
    // ------------------------------------------------------------------

    /**
     * Начислить награды за перечень впервые пройденных рангов (аналог V1
     * IRankListener::onNewRank). На каждый награждаемый ранг (MANAGER..VP) —
     * entitlement + проводка БС. Идемпотентно.
     *
     * @param string[] $crossedRankCodes коды пройденных рангов (любой набор)
     * @return int[] id созданных entitlement'ов (без уже существовавших)
     */
    public function onRankAchieved(
        int $memberId,
        array $crossedRankCodes,
        \DateTimeInterface $achievedAt,
        int $policyVersionId,
    ): array {
        $created = [];
        foreach ($crossedRankCodes as $code) {
            $entitlement = $this->grantStageOneForRank($memberId, (string) $code, $achievedAt, "rank:{$code}");
            if ($entitlement !== null) {
                $created[] = $entitlement->id;
            }
        }

        return $created;
    }

    /**
     * Идемпотентный драйвер по v2_rank_history (триггер наград — записи истории
     * рангов, а не Laravel-событие: T05 события не эмитит). Для участника проходит
     * все достигнутые к $at ранги MANAGER..VP и добирает недостающие entitlement'ы.
     * Используется AwardsStep для покупателя и его sponsor-аплайна.
     *
     * @return int[] id созданных entitlement'ов
     */
    public function reconcileMemberFromRankHistory(int $memberId, \DateTimeInterface $at): array
    {
        $rows = RankHistory::query()
            ->where('member_id', $memberId)
            ->where('achieved_at', '<=', $at)
            ->orderBy('rank_ordinal')
            ->get(['id', 'rank_code', 'rank_ordinal', 'achieved_at']);

        $created = [];
        foreach ($rows as $row) {
            // CLIENT (0) / CONSULTANT (1) наград не имеют.
            if ((int) $row->rank_ordinal < StatusCode::MANAGER->ordinal()) {
                continue;
            }
            $entitlement = $this->grantStageOneForRank(
                $memberId,
                (string) $row->rank_code,
                $row->achieved_at,
                "rankhist:{$row->id}",
            );
            if ($entitlement !== null) {
                $created[] = $entitlement->id;
            }
        }

        return $created;
    }

    /**
     * Награда этапа 1 за конкретный ранг. Сумма — снапшот PolicyVersion на дату
     * события. Ранги без награды (CLIENT/CONSULTANT) => null.
     */
    private function grantStageOneForRank(
        int $memberId,
        string $rankCode,
        \DateTimeInterface $at,
        string $triggerRef,
    ): ?AwardEntitlement {
        $policy = $this->policyResolver->forDate($at);
        if (! $this->isAwardBearingRank($policy, $rankCode)) {
            return null;
        }

        return $this->grantOne(
            $memberId,
            $rankCode,
            1,
            $this->amountForStage($policy, $rankCode, 1),
            AwardEntitlement::TRIGGER_RANK_ACHIEVED,
            $triggerRef,
            $policy->versionId(),
        );
    }

    // ------------------------------------------------------------------
    // Контракт GlobalQualificationAwardHook (VP этапы 2-3, DEC-042 спека A)
    // ------------------------------------------------------------------

    public function onGlobalQualificationCompleted(int $memberId, string $monthKey): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthKey)) {
            throw new \DomainException("Некорректный месяц квалификации: {$monthKey} (ожидается YYYY-MM)");
        }
        // Учитываются только квалификации в ранге VICE_PRESIDENT (ранг навсегда,
        // DEC-020 => наличие строки VP в истории). Квалификация ДО достижения VP
        // не порождает транша.
        $hasVp = RankHistory::query()
            ->where('member_id', $memberId)
            ->where('rank_code', AwardEntitlement::CODE_VICE_PRESIDENT)
            ->exists();
        if (! $hasVp) {
            return;
        }

        DB::transaction(function () use ($memberId, $monthKey) {
            // Сериализуем решение об этапе, залочив уже выданные транши VP 2/3.
            $tranches = AwardEntitlement::query()
                ->where('member_id', $memberId)
                ->where('award_code', AwardEntitlement::CODE_VICE_PRESIDENT)
                ->whereIn('stage_no', [2, 3])
                ->lockForUpdate()
                ->get()
                ->keyBy('stage_no');

            // Тот же месяц уже зачтён (повторная доставка) => no-op.
            foreach ($tranches as $tranche) {
                if ($tranche->trigger_ref === $monthKey) {
                    return;
                }
            }

            if (! $tranches->has(2)) {
                // Первая distinct месячная квалификация => этап 2.
                $this->grantVpTranche($memberId, 2, $monthKey);
            } elseif (! $tranches->has(3)) {
                // Этап 2 уже за ДРУГОЙ месяц (тот же отсеян выше) => вторая distinct => этап 3.
                $this->grantVpTranche($memberId, 3, $monthKey);
            }
            // Оба этапа заполнены => этапа 4 нет, ничего не делаем.
        });
    }

    private function grantVpTranche(int $memberId, int $stageNo, string $monthKey): ?AwardEntitlement
    {
        $policy = $this->policyResolver->current();

        return $this->grantOne(
            $memberId,
            AwardEntitlement::CODE_VICE_PRESIDENT,
            $stageNo,
            $this->amountForStage($policy, AwardEntitlement::CODE_VICE_PRESIDENT, $stageNo),
            AwardEntitlement::TRIGGER_GLOBAL_QUALIFICATION,
            $monthKey,
            $policy->versionId(),
            ['month' => $monthKey],
        );
    }

    // ------------------------------------------------------------------
    // Единичный грант: entitlement + проводка БС в ОДНОЙ транзакции, идемпотентно
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $meta
     * @return ?AwardEntitlement null = награда уже существовала (no-op)
     */
    private function grantOne(
        int $memberId,
        string $awardCode,
        int $stageNo,
        int $amountCents,
        string $triggerType,
        string $triggerRef,
        int $policyVersionId,
        array $meta = [],
    ): ?AwardEntitlement {
        if ($amountCents <= 0) {
            throw new \DomainException("Сумма награды должна быть > 0 ({$awardCode}/{$stageNo})");
        }

        return DB::transaction(function () use (
            $memberId, $awardCode, $stageNo, $amountCents, $triggerType, $triggerRef, $policyVersionId, $meta
        ): ?AwardEntitlement {
            $now = now();
            // ON CONFLICT DO NOTHING (unique member,code,stage) — без исключения и
            // без Postgres-aborted-tx; конкурентный повтор => 0 строк => no-op.
            $inserted = DB::table('v2_award_entitlements')->insertOrIgnore([
                'member_id' => $memberId,
                'award_code' => $awardCode,
                'stage_no' => $stageNo,
                'amount_cents' => $amountCents,
                'policy_version_id' => $policyVersionId,
                'trigger_type' => $triggerType,
                'trigger_ref' => $triggerRef,
                'status' => AwardEntitlement::STATUS_GRANTED,
                'granted_at' => $now,
                'posted_at' => $now,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($inserted === 0) {
                return null;
            }

            /** @var AwardEntitlement $entitlement */
            $entitlement = AwardEntitlement::query()
                ->where('member_id', $memberId)
                ->where('award_code', $awardCode)
                ->where('stage_no', $stageNo)
                ->firstOrFail();

            // Проводка на БС кредит-лотом (без сгорания — MF-9), source_type='award'.
            $this->wallet->credit(
                $memberId,
                LedgerV2::SUBACCOUNT_BS,
                $amountCents,
                "v2award:{$memberId}:{$awardCode}:{$stageNo}",
                null,      // expiresAt null => award-лот не сгорает
                'award',   // sourceType (в лот + meta.v2_source)
                $entitlement->id,
            );

            return $entitlement;
        });
    }

    // ------------------------------------------------------------------
    // Ручной payout-контур (owner-only на роутах)
    // ------------------------------------------------------------------

    /**
     * Отметить награду выплаченной вручную: адресная проводка award-лота
     * БС → company_payouts_paid ровно на amount_cents, статус paid_out, аудит.
     * Идемпотентно: повторный markPaid уже выплаченной => no-op. on_hold/forfeited
     * => AwardConflictException.
     */
    public function markPaid(int $entitlementId, int $adminId, ?string $note = null): AwardEntitlement
    {
        return DB::transaction(function () use ($entitlementId, $adminId, $note): AwardEntitlement {
            $entitlement = AwardEntitlement::query()->where('id', $entitlementId)->lockForUpdate()->first();
            if ($entitlement === null) {
                throw new AwardNotFoundException("Награда #{$entitlementId} не найдена");
            }
            if ($entitlement->status === AwardEntitlement::STATUS_PAID_OUT) {
                return $entitlement; // идемпотентный повтор
            }
            if ($entitlement->status !== AwardEntitlement::STATUS_GRANTED) {
                throw new AwardConflictException(
                    "Награда #{$entitlementId} в статусе {$entitlement->status}: выплата недоступна",
                );
            }

            // Порядок локов: entitlement → account → лот (как expireLots: account до лота).
            $account = $this->wallet->lockAccount($entitlement->member_id);
            $key = "v2award:paid:{$entitlement->id}";

            if (! $this->poster->alreadyPosted($key)) {
                $lot = WalletLotV2::query()
                    ->where('member_id', $entitlement->member_id)
                    ->where('account', WalletLotV2::ACCOUNT_BS)
                    ->where('source_type', 'award')
                    ->where('source_id', $entitlement->id)
                    ->where('status', WalletLotV2::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();
                if ($lot === null || $lot->available_cents < $entitlement->amount_cents) {
                    throw new AwardConflictException(
                        "Award-лот награды #{$entitlement->id} недоступен или израсходован — выплата невозможна",
                    );
                }

                $txId = $this->poster->post([
                    $this->poster->leg($entitlement->member_id, LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::DR, $entitlement->amount_cents),
                    $this->poster->leg(null, LedgerService::ACC_PAYOUTS_PAID, LedgerPostingV2Service::CR, $entitlement->amount_cents),
                ], 'award_payout', $entitlement->id, $key, [
                    'award_code' => $entitlement->award_code,
                    'stage' => $entitlement->stage_no,
                ]);

                // АДРЕСНОЕ списание именно award-лота (не FIFO — award-лот без срока
                // потреблялся бы последним, съедая чужие покупочные БС-лоты).
                $lot->available_cents -= $entitlement->amount_cents;
                if ($lot->available_cents === 0) {
                    $lot->status = WalletLotV2::STATUS_EXHAUSTED;
                }
                $lot->save();

                WalletLotConsumptionV2::query()->create([
                    'lot_id' => $lot->id,
                    'amount_cents' => $entitlement->amount_cents,
                    'reason' => WalletLotConsumptionV2::REASON_DEBIT,
                    'tx_id' => $txId,
                    'created_at' => now(),
                ]);

                $account->bs_available_cents -= $entitlement->amount_cents;
                $this->wallet->saveAccount($account);
            }

            $entitlement->status = AwardEntitlement::STATUS_PAID_OUT;
            $entitlement->paid_at = now();
            $entitlement->paid_by_admin_id = $adminId;
            if ($note !== null) {
                $entitlement->note = $note;
            }
            $entitlement->save();

            $this->audit->record($adminId, 'v2.award.mark_paid', 'award', $entitlement->id, null, [
                'amount_cents' => $entitlement->amount_cents,
                'award_code' => $entitlement->award_code,
                'stage' => $entitlement->stage_no,
            ]);

            return $entitlement;
        });
    }

    /** Приостановить выплату (granted → on_hold). */
    public function hold(int $entitlementId, int $adminId, ?string $reason = null): AwardEntitlement
    {
        return $this->transition(
            $entitlementId,
            $adminId,
            [AwardEntitlement::STATUS_GRANTED],
            AwardEntitlement::STATUS_ON_HOLD,
            'v2.award.hold',
            $reason,
        );
    }

    /** Снять паузу (on_hold → granted). */
    public function release(int $entitlementId, int $adminId, ?string $reason = null): AwardEntitlement
    {
        return $this->transition(
            $entitlementId,
            $adminId,
            [AwardEntitlement::STATUS_ON_HOLD],
            AwardEntitlement::STATUS_GRANTED,
            'v2.award.release',
            $reason,
        );
    }

    /**
     * Отказ от выплаты (granted|on_hold → forfeited). ТОЛЬКО для непроведённых
     * выплат (paid_out нельзя). Начисление НЕ удаляется, reversal-проводок НЕТ
     * (DEC-027/DEC-041/043) — статус + аудит.
     */
    public function forfeit(int $entitlementId, int $adminId, string $reason): AwardEntitlement
    {
        if (trim($reason) === '') {
            throw new AwardConflictException('forfeit требует причину (reason)');
        }

        return $this->transition(
            $entitlementId,
            $adminId,
            [AwardEntitlement::STATUS_GRANTED, AwardEntitlement::STATUS_ON_HOLD],
            AwardEntitlement::STATUS_FORFEITED,
            'v2.award.forfeit',
            $reason,
        );
    }

    /**
     * @param string[] $allowedFrom
     */
    private function transition(
        int $entitlementId,
        int $adminId,
        array $allowedFrom,
        string $toStatus,
        string $auditAction,
        ?string $reason,
    ): AwardEntitlement {
        return DB::transaction(function () use ($entitlementId, $adminId, $allowedFrom, $toStatus, $auditAction, $reason): AwardEntitlement {
            $entitlement = AwardEntitlement::query()->where('id', $entitlementId)->lockForUpdate()->first();
            if ($entitlement === null) {
                throw new AwardNotFoundException("Награда #{$entitlementId} не найдена");
            }
            if (! in_array($entitlement->status, $allowedFrom, true)) {
                throw new AwardConflictException(
                    "Награда #{$entitlementId} в статусе {$entitlement->status}: переход в {$toStatus} недоступен",
                );
            }

            $before = ['status' => $entitlement->status];
            $entitlement->status = $toStatus;
            if ($reason !== null && $reason !== '') {
                $entitlement->note = $reason;
            }
            $entitlement->save();

            $this->audit->record($adminId, $auditAction, 'award', $entitlement->id, $before, [
                'status' => $toStatus,
                'reason' => $reason,
            ]);

            return $entitlement;
        });
    }

    // ------------------------------------------------------------------
    // Каталог наград из PolicyVersion (хардкода сумм в коде нет)
    // ------------------------------------------------------------------

    private function isAwardBearingRank(PolicyV2 $policy, string $rankCode): bool
    {
        if ($rankCode === AwardEntitlement::CODE_VICE_PRESIDENT) {
            return true;
        }

        return array_key_exists($rankCode, $policy->award()->byStatusCents);
    }

    /** Сумма награды в USD-центах из конфига политики для ранга/этапа. */
    private function amountForStage(PolicyV2 $policy, string $rankCode, int $stageNo): int
    {
        $award = $policy->award();

        if ($rankCode === AwardEntitlement::CODE_VICE_PRESIDENT) {
            foreach ($award->vpTranches as $tranche) {
                if ((int) $tranche['sequence'] === $stageNo) {
                    return (int) $tranche['amount_cents'];
                }
            }
            throw new \DomainException("Нет транша VP для этапа {$stageNo}");
        }

        if (! array_key_exists($rankCode, $award->byStatusCents)) {
            throw new \DomainException("Нет награды для статуса {$rankCode}");
        }

        return (int) $award->byStatusCents[$rankCode];
    }
}
