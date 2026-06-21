<?php

namespace Modules\Calculator\Services;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Domain\CompensationEngine;
use Modules\Calculator\Models\ActivationEvent;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\MemberBonusLine;
use Modules\Calculator\Models\MemberEarning;
use Modules\Calculator\Repositories\EloquentNetworkRepository;
use Modules\Calculator\Repositories\EloquentPlanRepository;
use Modules\Calculator\Services\Telegram\TelegramNotifications;
use Modules\Calculator\Services\Telegram\TelegramNotifier;

/**
 * Активация пакета (мок-оплата) как идемпотентное событие. По событию активирует
 * пакет участника и пересчитывает всю сеть доменным ядром, сохраняя снимок
 * начислений (member_bonus_lines + member_earnings) и рангов. Без ledger/денег.
 *
 * Пересчёт всей сети — приемлемо для объёма MVP; инкрементальный пересчёт — позже.
 */
class ActivationService
{
    public function __construct(
        private readonly EloquentNetworkRepository $networkRepository,
        private readonly EloquentPlanRepository $planRepository,
        private readonly TelegramNotifier $notifier,
        private readonly LedgerService $ledger,
    ) {
    }

    public function activate(int $memberId, int $packageId, string $idempotencyKey): ActivationEvent
    {
        $oldRank = null;
        $applied = false;

        $event = DB::transaction(function () use ($memberId, $packageId, $idempotencyKey, &$oldRank, &$applied) {
            // exactly-once, в т.ч. под конкуренцией: ON CONFLICT DO NOTHING не роняет
            // транзакцию (в отличие от firstOrCreate), а параллельная вставка того же
            // ключа сериализуется и вернёт 0 → пересчёт не повторяется.
            $inserted = ActivationEvent::query()->insertOrIgnore([
                'member_id' => $memberId,
                'package_id' => $packageId,
                'idempotency_key' => $idempotencyKey,
                'status' => 'applied',
                'created_at' => now(),
            ]);

            $event = ActivationEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

            if ($inserted === 0) {
                return $event; // ключ уже обработан — идемпотентно
            }

            $member = Member::query()->where('id', $memberId)->lockForUpdate()->firstOrFail();
            $oldRank = $member->rank_id;
            $member->package_id = $packageId;
            $member->status = 'active';
            $member->save();

            $this->recompute($event->id);
            $applied = true;

            return $event;
        });

        // Уведомления — строго ПОСЛЕ коммита (best-effort). Через DB::afterCommit, чтобы при
        // вызове внутри внешней транзакции (webhook оплаты заказа) не выстрелить до её коммита;
        // вне транзакции (endpoint активации) колбэк выполнится немедленно.
        if ($applied) {
            DB::afterCommit(fn () => $this->notifyActivation($memberId, (int) $oldRank, $packageId));
        }

        return $event;
    }

    /** Best-effort Telegram-уведомления по факту активации (вне транзакции). */
    private function notifyActivation(int $memberId, int $oldRank, int $packageId): void
    {
        if (!$this->notifier->isEnabled()) {
            return; // быстрый выход без лишних запросов, когда уведомления выключены
        }

        $member = Member::query()->find($memberId);
        if ($member === null) {
            return;
        }

        $package = $this->planRepository->load()->package($packageId);
        $total = (string) (MemberEarning::query()->where('member_id', $memberId)->value('total') ?? '0.00');

        if ($member->telegram_id) {
            $this->notifier->notify(
                (int) $member->telegram_id,
                TelegramNotifications::packageActivated($package?->name ?? ('#' . $packageId), $total),
            );

            if ($member->rank_id && (int) $member->rank_id > $oldRank) {
                $alias = DB::table('calculator_ranks')->where('id', $member->rank_id)->value('alias');
                if ($alias) {
                    $this->notifier->notify((int) $member->telegram_id, TelegramNotifications::rankAchieved($alias));
                }
            }
        }

        if ($member->sponsor_id) {
            $sponsor = Member::query()->find($member->sponsor_id);
            if ($sponsor?->telegram_id) {
                $this->notifier->notify(
                    (int) $sponsor->telegram_id,
                    TelegramNotifications::newReferralActivated($member->name ?? ('#' . $member->id)),
                );
            }
        }
    }

    /** Полный пересчёт сети и перезапись снимка начислений/рангов + дельта-проводки в ledger. */
    private function recompute(int $eventId): void
    {
        $plan = $this->planRepository->load();
        $network = $this->networkRepository->load();
        $result = (new CompensationEngine($plan))->calculate($network);

        // Снимок ПРЕДЫДУЩЕГО дохода по узлам (в центах) — до перезаписи снимка.
        // Дельта (new − prev) уйдёт в ledger как корректирующие проводки (см. ниже).
        $prevCents = MemberEarning::query()->pluck('total', 'member_id')
            ->map(fn ($total) => $this->decimalToCents((string) $total))
            ->all();

        MemberBonusLine::query()->delete();
        MemberEarning::query()->delete();

        $now = now();
        $byMember = []; // memberId => ['total'=>cents, 'by_type'=>[type=>cents]]

        foreach ($result->lines() as $line) {
            MemberBonusLine::query()->create([
                'recipient_member_id' => $line->recipientId,
                'type' => $line->type,
                'amount' => $this->centsToDecimal($line->amount->cents),
                'basis' => [
                    'level' => $line->level,
                    'sourceId' => $line->sourceId,
                    'meta' => $line->meta,
                ],
                'source_event_id' => $eventId,
                'calculated_at' => $now,
            ]);

            $byMember[$line->recipientId]['total'] = ($byMember[$line->recipientId]['total'] ?? 0) + $line->amount->cents;
            $byMember[$line->recipientId]['by_type'][$line->type] =
                ($byMember[$line->recipientId]['by_type'][$line->type] ?? 0) + $line->amount->cents;
        }

        foreach ($byMember as $memberId => $agg) {
            MemberEarning::query()->create([
                'member_id' => $memberId,
                'total' => $this->centsToDecimal($agg['total']),
                'by_type' => array_map(fn (int $c) => $this->centsToDecimal($c), $agg['by_type']),
                'updated_at' => $now,
            ]);
        }

        // Дельта-проводки в ledger: для каждого затронутого узла Δ = новый_доход − прежний.
        // Узлы из prev и new объединяем (доход узла мог обнулиться). Проводка идемпотентна
        // по ключу события: повтор активации сюда не доходит (recompute не вызывается).
        $newCents = [];
        foreach ($byMember as $memberId => $agg) {
            $newCents[$memberId] = $agg['total'];
        }
        foreach (array_unique([...array_keys($prevCents), ...array_keys($newCents)]) as $memberId) {
            $delta = ($newCents[$memberId] ?? 0) - ($prevCents[$memberId] ?? 0);
            if ($delta !== 0) {
                $this->ledger->accrual((int) $memberId, $delta, $eventId, "accrual:ae{$eventId}:m{$memberId}");
            }
        }

        // Снимок рангов: ядро проставило финальные ранги узлам при пересчёте.
        foreach ($network->orderedById() as $node) {
            Member::query()->where('id', $node->id)->update([
                'rank_id' => $node->rankId ?: null,
            ]);
        }
    }

    /** Центы → строка decimal "D.CC" без float (доход ≥ 0). */
    private function centsToDecimal(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Строка decimal "D.CC" → целые центы без float-потерь (доход всегда ≥ 0). */
    private function decimalToCents(string $value): int
    {
        [$int, $frac] = array_pad(explode('.', $value, 2), 2, '0');
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return (int) $int * 100 + (int) $frac;
    }
}
