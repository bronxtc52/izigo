<?php

namespace Modules\Calculator\V2\Services\Refunds;

use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Services\ActivationService;
use Modules\Calculator\V2\Models\OrderReturn;
use Modules\Calculator\V2\Models\OrderReturnLine;
use Modules\Calculator\V2\Models\OrderVolumeSnapshot;
use Modules\Calculator\V2\Services\Refunds\Exceptions\RefundValidationException;
use Modules\Calculator\V2\Services\Volume\PolicyVersionIdProvider;

/**
 * T12: оркестратор возвратов/сторно. Единственный вход создания возврата.
 *
 * Дисциплина (amendments nice-to-have #5, контракт-чек W2+ №4): внешний оркестратор
 * события — здесь; берёт advisory-lock ACTIVATION_LOCK (тот же, что оплата/закрытие
 * периода/пересчёт) на ВСЮ транзакцию возврата — исключает гонку с конкурентным
 * пересчётом сети. Внутренние сервисы (PvLotIngest T03) лишь assertLockHeld().
 *
 * Идемпотентность: idempotency_key уникален (повтор POST того же возврата = та же
 * строка, no-op). Возврат денег покупателю (USDT) — ВНЕ системы: фиксируем факт +
 * сторнируем внутренние начисления, покупателю на ОС ничего не зачисляем (DEC-012/027).
 */
class RefundService
{
    public function __construct(
        private readonly ReversalPlanner $planner,
        private readonly PvLotReversalService $pvLots,
        private readonly BonusReversalService $bonus,
        private readonly RequalificationService $requal,
        private readonly PolicyVersionIdProvider $policyIds,
    ) {
    }

    /**
     * Создать и провести возврат. Идемпотентно по $idempotencyKey.
     *
     * @param array<int,array{order_item_id:int,qty:int}> $requestedLines для partial
     */
    public function create(
        int $orderId,
        string $kind,
        array $requestedLines,
        string $reason,
        ?int $adminId,
        string $idempotencyKey,
    ): OrderReturn {
        if (! in_array($kind, [OrderReturn::KIND_FULL, OrderReturn::KIND_PARTIAL], true)) {
            throw new RefundValidationException('kind должен быть full|partial');
        }
        if (trim($reason) === '') {
            throw new RefundValidationException('reason обязателен');
        }

        // Идемпотентность до лока (быстрый путь): тот же возврат уже создан.
        $existing = OrderReturn::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->fresh(['lines', 'actions', 'corrections']);
        }

        $order = Order::query()->where('id', $orderId)->first();
        if ($order === null) {
            throw new RefundValidationException('Заказ не найден');
        }
        // Возврат допустим только для ОПЛАЧЕННОГО заказа. Повторный полный возврат
        // упирается сюда же: первый перевёл заказ в refunded, статус != paid → 422.
        if ($order->status !== Order::STATUS_PAID) {
            throw new RefundValidationException('Возврат возможен только для оплаченного заказа');
        }

        $plan = $this->planner->planLines($order, $kind, $requestedLines);

        $policyVersionId = (int) (OrderVolumeSnapshot::query()
            ->where('order_id', $order->id)
            ->value('policy_version_id')
            ?? $this->policyIds->forDate(now()));

        return DB::transaction(function () use (
            $order, $kind, $reason, $adminId, $idempotencyKey, $plan, $policyVersionId
        ) {
            $this->acquireActivationLock();

            // Повторная проверка идемпотентности под локом (гонка дублей).
            $existing = OrderReturn::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing->fresh(['lines', 'actions', 'corrections']);
            }
            // Заказ мог быть возвращён параллельно между быстрым путём и локом.
            $fresh = Order::query()->where('id', $order->id)->lockForUpdate()->first();
            if ($fresh === null || $fresh->status !== Order::STATUS_PAID) {
                throw new RefundValidationException('Возврат возможен только для оплаченного заказа');
            }

            $return = OrderReturn::query()->create([
                'order_id' => $order->id,
                'member_id' => $order->member_id,
                'kind' => $kind,
                'status' => OrderReturn::STATUS_REVERSING,
                'reason' => $reason,
                'returned_bv_cents' => $plan['returned_bv_cents'],
                'returned_pv' => $plan['returned_pv'],
                'policy_version_id' => $policyVersionId,
                'created_by_admin_id' => $adminId,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($plan['lines'] as $line) {
                OrderReturnLine::query()->create([
                    'return_id' => $return->id,
                    'order_item_id' => $line['order_item_id'],
                    'qty' => $line['qty'],
                    'returned_pv' => $line['returned_pv'],
                    'returned_bv_cents' => $line['returned_bv_cents'],
                ]);
            }

            $needsManual = $this->executeReversal($return);

            $return->status = $needsManual
                ? OrderReturn::STATUS_NEEDS_MANUAL
                : OrderReturn::STATUS_REVERSED;
            $return->save();

            // Возврат денег покупателю — вне системы; фиксируем факт статусом заказа.
            $fresh->status = Order::STATUS_REFUNDED;
            $fresh->save();

            return $return->fresh(['lines', 'actions', 'corrections']);
        });
    }

    /**
     * Прогон reversal-chain. Возвращает true, если предложены корректировки закрытых
     * периодов (нужно ручное утверждение → статус возврата needs_manual).
     */
    private function executeReversal(OrderReturn $return): bool
    {
        // 1) PV-лоты (реверс несматченного; сматченные — на каскад).
        $pv = $this->pvLots->reverseForReturn($return);

        // 2) Реферальная — немедленное точное сторно на ОС.
        $this->bonus->reverseReferralForReturn($return);

        // 3) Структурная/лидерская по сматченным лотам — корректировки закрытых периодов.
        $proposals = $this->bonus->proposePeriodEffectsForReturn($return, $pv['affected_match_ids']);

        // 4) Пере-оценка квалификаций (ранг/награда/тир — навсегда; только PV-база).
        $this->requal->recordForReturn($return);

        return $proposals > 0;
    }

    /** Advisory-lock оркестратора (только pgsql; юнит-контекст без advisory-локов — no-op). */
    private function acquireActivationLock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('SELECT pg_advisory_xact_lock(?)', [ActivationService::ACTIVATION_LOCK_KEY]);
    }
}
