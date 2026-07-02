<?php

namespace Modules\Calculator\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Calculator\Models\Lead;
use Modules\Calculator\Models\Member;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Services\Payment\InvoiceResult;
use Modules\Calculator\Services\Payment\PaymentGateway;
use Modules\Calculator\Services\Payment\WebhookEvent;
use RuntimeException;

/**
 * Приём оплаты (Фаза 4, S3). Создаёт инвойсы в платёжном шлюзе и обрабатывает webhook
 * идемпотентно. Оплаченный заказ → OrderService::markPaid (активация — S4); оплаченное
 * пополнение → LedgerService::deposit на баланс партнёра.
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderService $orders,
        private readonly LedgerService $ledger,
    ) {
    }

    /** Инвойс на оплату заказа партнёра. Заказ должен быть его и в статусе pending_payment. */
    public function startOrderPayment(Member $member, int $orderId): array
    {
        $order = Order::query()
            ->where('member_id', $member->id)
            ->where('id', $orderId)
            ->first();
        if ($order === null) {
            throw new RuntimeException('Заказ не найден');
        }
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            throw new RuntimeException('Заказ уже не ожидает оплаты');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'member_id' => $member->id,
            'provider' => str_contains((string) config('calculator.payment_gateway'), 'ton') ? 'ton_pay' : 'fake',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => $order->total_usdt_cents,
            'currency' => config('calculator.commerce_currency', 'USDT'),
            'status' => Payment::STATUS_CREATED,
            'external_ref' => null, // заполним pay:{id} в issueInvoice (нужен id); null безопасен под unique
        ]);

        return $this->issueInvoice($payment, (int) $member->telegram_id, Payment::PURPOSE_ORDER);
    }

    /**
     * Инвойс на оплату заказа ЛИДА (первая покупка). Заказ должен быть его (lead_id) и
     * pending. Платёж создаётся с lead_id (member_id=null до промоушна на оплате).
     */
    public function startOrderPaymentForLead(Lead $lead, int $orderId): array
    {
        $order = Order::query()
            ->where('lead_id', $lead->id)
            ->where('id', $orderId)
            ->first();
        if ($order === null) {
            throw new RuntimeException('Заказ не найден');
        }
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            throw new RuntimeException('Заказ уже не ожидает оплаты');
        }

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'member_id' => null,
            'lead_id' => $lead->id,
            'provider' => str_contains((string) config('calculator.payment_gateway'), 'ton') ? 'ton_pay' : 'fake',
            'purpose' => Payment::PURPOSE_ORDER,
            'amount_cents' => $order->total_usdt_cents,
            'currency' => config('calculator.commerce_currency', 'USDT'),
            'status' => Payment::STATUS_CREATED,
            'external_ref' => null,
        ]);

        return $this->issueInvoice($payment, (int) $lead->telegram_id, Payment::PURPOSE_ORDER);
    }

    /** Инвойс на пополнение внутреннего USDT-баланса (для autoship). */
    public function startTopup(Member $member, int $amountCents): array
    {
        if ($amountCents <= 0) {
            throw new RuntimeException('Сумма пополнения должна быть положительной');
        }

        $payment = Payment::query()->create([
            'order_id' => null,
            'member_id' => $member->id,
            'provider' => str_contains((string) config('calculator.payment_gateway'), 'ton') ? 'ton_pay' : 'fake',
            'purpose' => Payment::PURPOSE_TOPUP,
            'amount_cents' => $amountCents,
            'currency' => config('calculator.commerce_currency', 'USDT'),
            'status' => Payment::STATUS_CREATED,
            'external_ref' => null,
        ]);

        return $this->issueInvoice($payment, (int) $member->telegram_id, Payment::PURPOSE_TOPUP);
    }

    /** Создать инвойс в шлюзе и сохранить ref/external_id. */
    private function issueInvoice(Payment $payment, int $telegramId, string $purpose): array
    {
        $ref = "pay:{$payment->id}";
        $invoice = $this->gateway->createInvoice(
            $payment->amount_cents,
            $payment->currency,
            $purpose,
            $ref,
            ['telegram_id' => $telegramId],
        );

        $payment->external_ref = $ref;
        $payment->external_id = $invoice->providerId;
        $payment->status = Payment::STATUS_PENDING;
        $payment->save();

        return [
            'payment_id' => $payment->id,
            'pay_url' => $invoice->payUrl,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
            // Для TON Pay UI на фронте: куда и с каким memo платить (non-custodial).
            'memo' => $ref,
            'merchant_address' => (string) config('calculator.ton_merchant_address', ''),
        ];
    }

    /**
     * Обработать webhook платёжного шлюза. Возвращает массив-результат; бросает
     * RuntimeException при невалидной подписи/несоответствии (хендлер ответит 400).
     */
    public function handleWebhook(Request $request): array
    {
        $event = $this->gateway->verifyAndParseWebhook($request);
        if ($event === null) {
            throw new RuntimeException('Невалидная подпись webhook');
        }

        $payment = Payment::query()->where('external_ref', $event->externalRef)->first();
        if ($payment === null) {
            throw new RuntimeException('Платёж не найден');
        }

        // Идемпотентность: уже оплачен — повторный callback не пере-исполняем.
        if ($payment->status === Payment::STATUS_PAID) {
            return ['status' => 'ok', 'idempotent' => true];
        }

        if ($event->status !== WebhookEvent::PAID) {
            $payment->status = $event->status === WebhookEvent::FAILED
                ? Payment::STATUS_FAILED
                : Payment::STATUS_EXPIRED;
            $payment->raw_payload = $event->raw;
            $payment->save();

            return ['status' => 'ok', 'payment_status' => $payment->status];
        }

        // Защита от подмены суммы.
        if ($event->amountCents !== $payment->amount_cents) {
            throw new RuntimeException('Сумма webhook не совпадает с платежом');
        }

        DB::transaction(function () use ($payment, $event) {
            $locked = Payment::query()->where('id', $payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === Payment::STATUS_PAID) {
                return; // победитель гонки уже исполнил
            }
            $locked->external_id = $locked->external_id ?: $event->providerId;
            $locked->raw_payload = $event->raw;
            $this->applyPaid($locked);
        });

        return ['status' => 'ok', 'payment_status' => Payment::STATUS_PAID];
    }

    /**
     * Опрос «висящих» платежей в сети (TON Pay, non-custodial). Для каждого pending
     * спрашивает шлюз pollStatus(memo, сумма): paid → исполняем, failed → помечаем.
     * Вызывается командой commerce:tonpay-poll. Для webhook-драйверов pollStatus='none'.
     */
    public function pollPending(): array
    {
        $pending = Payment::query()
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('external_ref')
            ->orderBy('id')
            ->get();

        $confirmed = 0;
        $failed = 0;
        $pollErrors = 0;   // опрос не удался (сеть/API) — платёж не трогаем и от TTL защищаем
        $polledOkIds = []; // опрошены успешно, перевода нет — единственные кандидаты на TTL
        foreach ($pending as $payment) {
            try {
                $status = $this->gateway->pollStatus($payment->external_ref, $payment->amount_cents);
                if ($status === 'error') {
                    $pollErrors++;
                    continue;
                }
                if ($status === 'paid') {
                    $this->confirmPayment($payment->id);
                    $confirmed++;
                } elseif ($status === 'failed') {
                    $payment->status = Payment::STATUS_FAILED;
                    $payment->save();
                    $failed++;
                } else {
                    $polledOkIds[] = $payment->id; // 'pending'/'none'
                }
            } catch (\Throwable $e) {
                // Poison-платёж (например, осиротевший order у confirmPayment) не должен
                // останавливать конвейер: соседние платежи подтверждаются, аварийный не
                // попадает в TTL-кандидаты (по нему могли прийти деньги).
                Log::error("tonpay-poll: платёж {$payment->id} упал при обработке: {$e->getMessage()}", [
                    'payment_id' => $payment->id,
                ]);
                report($e);
            }
        }

        // TTL: «висящие» pending, по которым оплата так и не пришла, истекают, чтобы не
        // копиться бессрочно. Экспирируем СТРОГО ПОСЛЕ опроса и ТОЛЬКО по белому списку
        // успешно опрошенных в этом прогоне («перевода нет») — платёж, чей опрос упал,
        // экспирировать нельзя: деньги могли прийти, а мы просто не смогли проверить.
        // Сироты без external_ref (недосозданный инвойс) неопрашиваемы и неоплачиваемы —
        // их TTL закрывает по-старому. Guard: все опросы упали → индексатор лежит, TTL пропускаем.
        $expired = 0;
        $ttlMinutes = (int) config('calculator.payment_pending_ttl_minutes', 1440);
        $allPollsFailed = $pending->isNotEmpty() && $pollErrors === $pending->count();
        if ($ttlMinutes > 0 && $allPollsFailed) {
            Log::warning('tonpay-poll: все опросы завершились ошибкой — TTL-экспирация пропущена');
        } elseif ($ttlMinutes > 0) {
            // orWhereNull — защитная ветка: PENDING сегодня всегда идёт с external_ref
            // (issueInvoice), но если инвариант сломается, неопрашиваемый и неоплачиваемый
            // платёж не должен зависнуть навечно.
            $expireIds = Payment::query()
                ->where('status', Payment::STATUS_PENDING)
                ->where('created_at', '<', now()->subMinutes($ttlMinutes))
                ->where(function ($q) use ($polledOkIds) {
                    $q->whereIn('id', $polledOkIds)->orWhereNull('external_ref');
                })
                ->pluck('id');
            if ($expireIds->isNotEmpty()) {
                // Платёж не должен умирать молча: полный список — в лог, в Sentry — сводка.
                Log::warning('tonpay-poll: TTL-экспирация pending-платежей', [
                    'count' => $expireIds->count(),
                    'ids' => $expireIds->all(),
                ]);
                \Sentry\captureMessage(
                    sprintf(
                        'tonpay-poll: TTL-экспирация %d pending-платежей (ids: %s%s)',
                        $expireIds->count(),
                        $expireIds->take(10)->implode(', '),
                        $expireIds->count() > 10 ? ', …' : ''
                    ),
                    \Sentry\Severity::warning()
                );
                $expired = Payment::query()
                    ->whereIn('id', $expireIds)
                    ->where('status', Payment::STATUS_PENDING)
                    ->update(['status' => Payment::STATUS_EXPIRED]);
            }
        }

        return ['confirmed' => $confirmed, 'failed' => $failed, 'expired' => $expired, 'errors' => $pollErrors];
    }

    /**
     * Принудительный ре-опрос платежа админом (owner/finance): для pending/expired заново
     * спрашивает сеть, найденные деньги подтверждают платёж (в т.ч. expired → paid — TTL мог
     * съесть платёж при недоступном индексаторе). Poll идёт ВНЕ транзакции (row-lock не
     * держится на время HTTP к tonapi); фиксация — коротким confirmPayment под локом,
     * идемпотентно к гонке с тиком поллера.
     */
    public function recheckAdmin(int $paymentId): array
    {
        $payment = Payment::query()->find($paymentId);
        if ($payment === null) {
            throw new RuntimeException('Платёж не найден');
        }
        if (!in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_EXPIRED], true)) {
            return ['payment_status' => $payment->status, 'poll' => 'skipped'];
        }
        if ($payment->external_ref === null) {
            return ['payment_status' => $payment->status, 'poll' => 'skipped'];
        }

        $status = $this->gateway->pollStatus($payment->external_ref, $payment->amount_cents);
        if ($status === 'paid') {
            $this->confirmPayment($payment->id);
        }

        return ['payment_status' => $payment->fresh()->status, 'poll' => $status];
    }

    /**
     * Немедленная проверка статуса своего платежа из кабинета (чтобы не ждать крон).
     * Опрашивает сеть для pending и при подтверждении исполняет. Возвращает статус.
     */
    public function checkForMember(Member $member, int $paymentId): array
    {
        $payment = Payment::query()
            ->where('id', $paymentId)
            ->where('member_id', $member->id)
            ->first();
        if ($payment === null) {
            throw new RuntimeException('Платёж не найден');
        }

        if ($payment->status === Payment::STATUS_PENDING) {
            $status = $this->gateway->pollStatus($payment->external_ref, $payment->amount_cents);
            if ($status === 'paid') {
                $this->confirmPayment($payment->id);

                return ['payment_status' => Payment::STATUS_PAID];
            }
            if ($status === 'failed') {
                $payment->status = Payment::STATUS_FAILED;
                $payment->save();

                return ['payment_status' => Payment::STATUS_FAILED];
            }
            // 'error' (опрос не удался) для пользователя неотличим от pending: статус не меняем.
        }

        return ['payment_status' => $payment->fresh()->status];
    }

    /**
     * Немедленная проверка статуса платежа ЛИДА (чекаут первой покупки). При подтверждении
     * confirmPayment промоутит лида в Member (см. OrderService::markPaid) — после чего
     * следующий заход уже резолвится как участник.
     */
    public function checkForLead(Lead $lead, int $paymentId): array
    {
        $payment = Payment::query()
            ->where('id', $paymentId)
            ->where('lead_id', $lead->id)
            ->first();
        if ($payment === null) {
            throw new RuntimeException('Платёж не найден');
        }

        if ($payment->status === Payment::STATUS_PENDING) {
            $status = $this->gateway->pollStatus($payment->external_ref, $payment->amount_cents);
            if ($status === 'paid') {
                $this->confirmPayment($payment->id);

                return ['payment_status' => Payment::STATUS_PAID];
            }
            if ($status === 'failed') {
                $payment->status = Payment::STATUS_FAILED;
                $payment->save();

                return ['payment_status' => Payment::STATUS_FAILED];
            }
            // 'error' (опрос не удался) для пользователя неотличим от pending: статус не меняем.
        }

        return ['payment_status' => $payment->fresh()->status];
    }

    /**
     * Подтвердить платёж по данным сети (идемпотентно, под локом). Подтверждается только
     * из PENDING/EXPIRED: перепроверка статуса ПОД локом закрывает гонку «во время опроса
     * платёж стал failed/paid другим путём» — конфликтующий терминальный статус не
     * перетирается тихо (failed→paid руками — только через разбор инцидента).
     */
    public function confirmPayment(int $paymentId): void
    {
        DB::transaction(function () use ($paymentId) {
            $locked = Payment::query()->where('id', $paymentId)->lockForUpdate()->first();
            if ($locked === null
                || !in_array($locked->status, [Payment::STATUS_PENDING, Payment::STATUS_EXPIRED], true)) {
                return;
            }
            $this->applyPaid($locked);
        });
    }

    /**
     * Зафиксировать платёж как оплаченный и исполнить fulfillment (заказ→активация /
     * пополнение). Вызывается ВНУТРИ транзакции из webhook и из poll.
     */
    private function applyPaid(Payment $locked): void
    {
        $locked->status = Payment::STATUS_PAID;
        $locked->paid_at = now();
        $locked->save();

        if ($locked->purpose === Payment::PURPOSE_ORDER && $locked->order_id !== null) {
            $this->orders->markPaid($locked->order_id);
            // Лид-платёж: markPaid промоутнул лида и проставил order.member_id —
            // переносим участника на платёж (lead_id обнулён FK при удалении лида).
            if ($locked->member_id === null) {
                $memberId = Order::query()->where('id', $locked->order_id)->value('member_id');
                if ($memberId !== null) {
                    $locked->member_id = (int) $memberId;
                    $locked->save();
                }
            }
        } elseif ($locked->purpose === Payment::PURPOSE_TOPUP) {
            $this->ledger->deposit($locked->member_id, $locked->amount_cents, "topup:{$locked->id}", $locked->id);
        } else {
            // Оплачен, но обработчика нет (order без order_id) — откат, чтобы не зафиксировать
            // «получено» без эффекта; webhook вернёт 400 для разбора.
            throw new RuntimeException("Платёж {$locked->id}: оплачен без обработчика (purpose={$locked->purpose})");
        }
    }
}
