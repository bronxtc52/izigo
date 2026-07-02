<?php

namespace Modules\Calculator\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Exceptions\InsufficientFundsException;
use Modules\Calculator\Models\AutoshipSubscription;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Product;
use RuntimeException;

/**
 * Autoship (Фаза 4, S6). Подписка периодически списывает цену тарифа с ВНУТРЕННЕГО
 * USDT-баланса партнёра (пополняется через Wallet Pay top-up) и проводит ре-покупку:
 * заказ → markPaid → активация (поддержание статуса; под моделью A повтор того же тарифа
 * не создаёт новых бонусов — лишь оборот от новых активаций сети). При нехватке средств —
 * повторы по расписанию д.3/7/14, затем пауза.
 */
class AutoshipService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly LedgerService $ledger,
        private readonly ActivationService $activations,
    ) {
    }

    /** Включить autoship на товар с периодом intervalDays. Первое списание — через период. */
    public function create(Member $member, int $productId, int $intervalDays): array
    {
        if ($intervalDays < 1) {
            throw new RuntimeException('Период должен быть ≥ 1 дня');
        }
        $product = Product::query()->where('id', $productId)->where('is_active', true)->first();
        if ($product === null) {
            throw new RuntimeException('Товар не найден или недоступен');
        }

        $sub = AutoshipSubscription::query()->create([
            'member_id' => $member->id,
            'product_id' => $product->id,
            'package_id' => $product->package_id,
            'interval_days' => $intervalDays,
            'next_charge_at' => now()->addDays($intervalDays),
            'status' => AutoshipSubscription::STATUS_ACTIVE,
            'retry_stage' => 0,
        ]);

        return $this->serialize($sub);
    }

    public function listForMember(Member $member): array
    {
        return AutoshipSubscription::query()
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AutoshipSubscription $s) => $this->serialize($s))
            ->all();
    }

    /** Сменить состояние подписки участника: pause|resume|cancel. */
    public function setState(Member $member, int $subId, string $action): array
    {
        $sub = AutoshipSubscription::query()
            ->where('member_id', $member->id)
            ->where('id', $subId)
            ->first();
        if ($sub === null) {
            throw new RuntimeException('Подписка не найдена');
        }

        $sub->status = match ($action) {
            'pause' => AutoshipSubscription::STATUS_PAUSED,
            'cancel' => AutoshipSubscription::STATUS_CANCELLED,
            'resume' => AutoshipSubscription::STATUS_ACTIVE,
            default => throw new RuntimeException('Неизвестное действие'),
        };
        if ($action === 'resume') {
            $sub->retry_stage = 0;
            if ($sub->next_charge_at->isPast()) {
                $sub->next_charge_at = now()->addDays($sub->interval_days);
            }
        }
        $sub->save();

        return $this->serialize($sub);
    }

    /**
     * Обработать все подписки, которым подошёл срок (вызывается scheduled-командой).
     * Возвращает сводку [charged, retried, paused].
     */
    public function runDue(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $summary = ['charged' => 0, 'retried' => 0, 'paused' => 0];

        $due = AutoshipSubscription::query()
            ->where('status', AutoshipSubscription::STATUS_ACTIVE)
            ->where('next_charge_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            try {
                $this->processOne((int) $id, $now, $summary);
            } catch (\Throwable $e) {
                // Poison-подписка (битый товар/заказ/данные) не должна останавливать прогон:
                // транзакция подписки откатилась (деньги не списаны), идём к следующей.
                Log::error("autoship-run: подписка {$id} упала при обработке: {$e->getMessage()}", [
                    'subscription_id' => $id,
                ]);
                report($e);
            }
        }

        return $summary;
    }

    /**
     * Обработать одну подписку. Списание, ре-покупка (заказ → markPaid → активация) и сдвиг
     * next_charge_at — ОДНА транзакция: сбой на любом шаге откатывает всё, включая charge
     * (деньги не списываются без активации). Advisory-lock активаций берётся ДО charge —
     * тот же порядок захвата, что у пути оплаты, иначе дедлок с конкурентным пересчётом.
     */
    private function processOne(int $id, Carbon $now, array &$summary): void
    {
        try {
            $charged = DB::transaction(function () use ($id, $now) {
                $sub = AutoshipSubscription::query()->where('id', $id)->lockForUpdate()->first();
                if ($sub === null || $sub->status !== AutoshipSubscription::STATUS_ACTIVE || $sub->next_charge_at->gt($now)) {
                    return null; // уже обработана/изменена другим процессом
                }

                $product = Product::query()->find($sub->product_id);
                if ($product === null || !$product->is_active) {
                    // Товар снят с продажи — ставим подписку на паузу.
                    $sub->status = AutoshipSubscription::STATUS_PAUSED;
                    $sub->save();

                    return 'paused';
                }

                $this->activations->acquireActivationLock();

                $cycle = $sub->next_charge_at->format('Ymd-His');
                $this->ledger->charge($sub->member_id, $product->price_usdt_cents, "autoship:{$sub->id}:{$cycle}", $sub->id);

                // Списание прошло → проводим ре-покупку (заказ + активация) в той же транзакции.
                $member = Member::query()->findOrFail($sub->member_id);
                $order = $this->orders->create($member, $product->id, 1, "autoship-order:{$sub->id}:{$cycle}");
                $this->orders->markPaid((int) $order['id']);

                $sub->next_charge_at = $sub->next_charge_at->copy()->addDays($sub->interval_days);
                $sub->retry_stage = 0;
                $sub->last_charge_at = $now;
                $sub->save();

                return 'charged';
            });
        } catch (InsufficientFundsException) {
            // Транзакция откатилась целиком; ступень повтора двигаем отдельной транзакцией.
            $sub = AutoshipSubscription::query()->find($id);
            if ($sub !== null) {
                $this->advanceRetry($sub, $now);
                $summary[$sub->status === AutoshipSubscription::STATUS_PAUSED ? 'paused' : 'retried']++;
            }

            return;
        }

        if ($charged !== null) {
            $summary[$charged]++;
        }
    }

    /** Сдвинуть на следующую ступень повтора (3→7→14), после исчерпания — пауза. */
    private function advanceRetry(AutoshipSubscription $sub, Carbon $now): void
    {
        $next = null;
        foreach (AutoshipSubscription::RETRY_STAGES as $stage) {
            if ($stage > $sub->retry_stage) {
                $next = $stage;
                break;
            }
        }

        if ($next === null) {
            $sub->status = AutoshipSubscription::STATUS_PAUSED;
            $sub->save();

            return;
        }

        $gap = $next - $sub->retry_stage; // 0→3:3, 3→7:4, 7→14:7 (кумулятивно 3/7/14 от провала)
        $sub->retry_stage = $next;
        $sub->next_charge_at = $now->copy()->addDays($gap);
        $sub->save();
    }

    private function serialize(AutoshipSubscription $sub): array
    {
        return [
            'id' => $sub->id,
            'product_id' => $sub->product_id,
            'package_id' => $sub->package_id,
            'interval_days' => $sub->interval_days,
            'next_charge_at' => optional($sub->next_charge_at)->toIso8601String(),
            'status' => $sub->status,
            'retry_stage' => $sub->retry_stage,
            'last_charge_at' => optional($sub->last_charge_at)->toIso8601String(),
        ];
    }
}
