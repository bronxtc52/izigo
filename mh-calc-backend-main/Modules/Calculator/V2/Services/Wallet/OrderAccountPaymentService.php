<?php

namespace Modules\Calculator\V2\Services\Wallet;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Calculator\Models\Order;
use Modules\Calculator\Models\Payment;
use Modules\Calculator\Models\V2\WalletLotConsumptionV2;
use Modules\Calculator\Models\V2\WalletLotV2;
use Modules\Calculator\Models\V2\WalletReservationV2;
use Modules\Calculator\Services\LedgerService;
use Modules\Calculator\V2\Services\Ledger\LedgerPostingV2Service;
use Modules\Calculator\V2\Services\Wallet\Exceptions\InsufficientAccountBalanceException;
use Modules\Calculator\V2\Services\Wallet\Exceptions\OsOrderLimitExceededException;
use Modules\Calculator\V2\Services\Wallet\Exceptions\ReservationConflictException;
use RuntimeException;

/**
 * mh-full-plan T02: оплата заказа с субсчетов — резерв ОС (≤70% стоимости) + БС (без
 * лимита, amendments nice-to-have #6: internal_funding_full_bv), капчер при markPaid,
 * освобождение при отмене. Остаток сверх резерва оплачивается TON-инвойсом
 * (PaymentService выставляет remainderCents).
 *
 * Резерв атомарный: списание лотов (reason=order_reserve) + перевод available→held.
 * Один живой резерв на заказ — партиал-unique индекс v2_wallet_reservations.
 */
class OrderAccountPaymentService
{
    public function __construct(
        private readonly LedgerPostingV2Service $poster,
        private readonly WalletAccountsV2Service $wallet,
        private readonly AccountsPolicyV2 $policy,
    ) {
    }

    /**
     * Зарезервировать средства субсчетов под заказ. Валидации:
     *  - заказ участника в pending_payment, без живого TON-инвойса (иначе инвойс на
     *    полную сумму мог бы быть оплачен поверх резерва — переплата);
     *  - os_cents ≤ intdiv(total * 7000, 10000) — лимит ОС 70% (округление вниз до цента);
     *  - os_cents + bs_cents ≤ total; достаточность балансов и лотов.
     *
     * @return array{reservation_id:int, os_cents:int, bs_cents:int, remainder_cents:int}
     */
    public function reserve(Order $order, int $osCents, int $bsCents): array
    {
        if ($order->member_id === null) {
            throw new RuntimeException('Оплата со счетов недоступна до первой покупки');
        }
        if ($order->status !== Order::STATUS_PENDING_PAYMENT) {
            throw new RuntimeException('Заказ уже не ожидает оплаты');
        }
        if ($osCents < 0 || $bsCents < 0 || ($osCents + $bsCents) <= 0) {
            throw new \DomainException('Суммы резерва должны быть неотрицательными, а итог — больше нуля');
        }

        $total = (int) $order->total_usdt_cents;
        $shareBp = $this->policy->osOrderPaymentMaxShareBp(now());
        $maxOs = intdiv($total * $shareBp, 10000);
        if ($osCents > $maxOs) {
            throw new OsOrderLimitExceededException(
                'С основного счёта можно оплатить не более ' . ($shareBp / 100) . '% заказа: лимит '
                . $maxOs . ', запрошено ' . $osCents,
            );
        }
        if ($osCents + $bsCents > $total) {
            throw new \DomainException('Сумма резерва превышает стоимость заказа');
        }

        return DB::transaction(function () use ($order, $osCents, $bsCents, $total) {
            // MF-6 (ревью W1): row-lock заказа — единая точка сериализации с
            // PaymentService::startOrderPayment (та же блокировка там). Без него
            // конкурентные «резерв + выставление инвойса» проходят обе проверки:
            // инвойс на полную сумму поверх резерва = переплата участника.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== Order::STATUS_PENDING_PAYMENT) {
                throw new RuntimeException('Заказ уже не ожидает оплаты');
            }

            // Живой TON-инвойс на полную сумму + резерв = риск переплаты. Сначала инвойс
            // должен истечь/провалиться, либо резерв делается ДО выставления инвойса.
            // Проверка — строго ПОСЛЕ взятия лока заказа.
            $liveInvoice = Payment::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])
                ->exists();
            if ($liveInvoice) {
                throw new ReservationConflictException(
                    'По заказу уже выставлен счёт на оплату — дождитесь его истечения или оплатите его',
                );
            }

            $account = $this->wallet->lockAccount($order->member_id);
            if ($account->os_available_cents < $osCents) {
                throw new InsufficientAccountBalanceException(
                    "Недостаточно средств на ОС: доступно {$account->os_available_cents}, требуется {$osCents}",
                );
            }
            if ($account->bs_available_cents < $bsCents) {
                throw new InsufficientAccountBalanceException(
                    "Недостаточно средств на БС: доступно {$account->bs_available_cents}, требуется {$bsCents}",
                );
            }

            try {
                $reservation = WalletReservationV2::query()->create([
                    'order_id' => $order->id,
                    'member_id' => $order->member_id,
                    'os_cents' => $osCents,
                    'bs_cents' => $bsCents,
                    'status' => WalletReservationV2::STATUS_RESERVED,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Партиал-unique «один живой резерв на заказ» сработал под гонкой.
                throw new ReservationConflictException('По заказу уже есть живой резерв средств');
            }

            $legs = [];
            if ($osCents > 0) {
                $legs[] = $this->poster->leg($order->member_id, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::DR, $osCents);
                $legs[] = $this->poster->leg($order->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::CR, $osCents);
            }
            if ($bsCents > 0) {
                $legs[] = $this->poster->leg($order->member_id, LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::DR, $bsCents);
                $legs[] = $this->poster->leg($order->member_id, LedgerPostingV2Service::ACC_BS_HELD, LedgerPostingV2Service::CR, $bsCents);
            }
            $txId = $this->poster->post($legs, 'acct_reserve', $order->id, "v2:acct_reserve:{$reservation->id}");

            if ($osCents > 0) {
                $this->wallet->consumeLots($order->member_id, WalletLotV2::ACCOUNT_OS, $osCents,
                    WalletLotConsumptionV2::REASON_ORDER_RESERVE, $txId, $reservation->id);
                $account->os_available_cents -= $osCents;
                $account->os_held_cents += $osCents;
            }
            if ($bsCents > 0) {
                $this->wallet->consumeLots($order->member_id, WalletLotV2::ACCOUNT_BS, $bsCents,
                    WalletLotConsumptionV2::REASON_ORDER_RESERVE, $txId, $reservation->id);
                $account->bs_available_cents -= $bsCents;
                $account->bs_held_cents += $bsCents;
            }
            $this->wallet->saveAccount($account);

            return [
                'reservation_id' => $reservation->id,
                'os_cents' => $osCents,
                'bs_cents' => $bsCents,
                'remainder_cents' => $total - $osCents - $bsCents,
            ];
        });
    }

    /** Живой (reserved) резерв заказа, если есть. */
    public function liveReservation(int $orderId): ?WalletReservationV2
    {
        return WalletReservationV2::query()
            ->where('order_id', $orderId)
            ->where('status', WalletReservationV2::STATUS_RESERVED)
            ->first();
    }

    /** Сколько осталось оплатить внешним платежом (TON) с учётом живого резерва. */
    public function remainderCents(Order $order): int
    {
        $res = $this->liveReservation($order->id);
        $reserved = $res === null ? 0 : $res->os_cents + $res->bs_cents;

        return max(0, (int) $order->total_usdt_cents - $reserved);
    }

    /**
     * Капчер живого резерва при оплате заказа (хук markPaid, до активации):
     * held → company_sales_revenue. Идемпотентно; нет живого резерва — no-op.
     * Лоты уже списаны при резерве — повторного consumption нет.
     */
    public function capture(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $res = WalletReservationV2::query()
                ->where('order_id', $orderId)
                ->where('status', WalletReservationV2::STATUS_RESERVED)
                ->lockForUpdate()
                ->first();
            if ($res === null) {
                return;
            }
            $key = "v2:acct_capture:{$res->id}";
            if ($this->poster->alreadyPosted($key)) {
                return;
            }

            $account = $this->wallet->lockAccount($res->member_id);

            $legs = [];
            if ($res->os_cents > 0) {
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::DR, $res->os_cents);
            }
            if ($res->bs_cents > 0) {
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_BS_HELD, LedgerPostingV2Service::DR, $res->bs_cents);
            }
            $legs[] = $this->poster->leg(null, LedgerService::ACC_SALES_REVENUE, LedgerPostingV2Service::CR, $res->os_cents + $res->bs_cents);
            $this->poster->post($legs, 'acct_reserve', $orderId, $key, ['op' => 'capture']);

            $account->os_held_cents -= $res->os_cents;
            $account->bs_held_cents -= $res->bs_cents;
            $this->wallet->saveAccount($account);

            $res->status = WalletReservationV2::STATUS_CAPTURED;
            $res->save();
        });
    }

    /**
     * Освободить живой резерв (отмена оплаты со счетов): held → available,
     * средства возвращаются в исходные лоты компенсирующими записями.
     * Запрещено при живом TON-инвойсе на остаток (инвойс уже выставлен на
     * remainder — освобождение резерва привело бы к недоплате заказа).
     */
    public function release(int $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            // MF-6: тот же row-lock заказа — освобождение резерва сериализуется с
            // выставлением инвойса на остаток (проверка «живой инвойс» ниже — под локом).
            Order::query()->whereKey($orderId)->lockForUpdate()->first();

            $res = WalletReservationV2::query()
                ->where('order_id', $orderId)
                ->where('status', WalletReservationV2::STATUS_RESERVED)
                ->lockForUpdate()
                ->first();
            if ($res === null) {
                return;
            }

            $liveInvoice = Payment::query()
                ->where('order_id', $orderId)
                ->whereIn('status', [Payment::STATUS_CREATED, Payment::STATUS_PENDING])
                ->exists();
            if ($liveInvoice) {
                throw new ReservationConflictException(
                    'По заказу выставлен счёт на остаток — отмена резерва невозможна, пока счёт жив',
                );
            }

            $key = "v2:acct_release:{$res->id}";
            if ($this->poster->alreadyPosted($key)) {
                return;
            }

            $account = $this->wallet->lockAccount($res->member_id);

            $legs = [];
            if ($res->os_cents > 0) {
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_OS_HELD, LedgerPostingV2Service::DR, $res->os_cents);
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_OS_AVAILABLE, LedgerPostingV2Service::CR, $res->os_cents);
            }
            if ($res->bs_cents > 0) {
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_BS_HELD, LedgerPostingV2Service::DR, $res->bs_cents);
                $legs[] = $this->poster->leg($res->member_id, LedgerPostingV2Service::ACC_BS_AVAILABLE, LedgerPostingV2Service::CR, $res->bs_cents);
            }
            $txId = $this->poster->post($legs, 'acct_reserve', $orderId, $key, ['op' => 'release']);

            $restored = $this->wallet->restoreLots(
                WalletLotConsumptionV2::REASON_ORDER_RESERVE,
                WalletLotConsumptionV2::REASON_RESERVE_RELEASE,
                $txId,
                reservationId: $res->id,
            );
            if ($restored !== $res->os_cents + $res->bs_cents) {
                throw new \DomainException(
                    "Возврат резерва {$res->id}: восстановлено {$restored} ≠ " . ($res->os_cents + $res->bs_cents),
                );
            }

            $account->os_held_cents -= $res->os_cents;
            $account->os_available_cents += $res->os_cents;
            $account->bs_held_cents -= $res->bs_cents;
            $account->bs_available_cents += $res->bs_cents;
            $this->wallet->saveAccount($account);

            $res->status = WalletReservationV2::STATUS_RELEASED;
            $res->save();
        });
    }
}
